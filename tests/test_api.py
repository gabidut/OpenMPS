"""End-to-end API contract tests; only temporary packs and a loopback PHP server."""
import base64, hashlib, io, json, os, pathlib, re, shutil, socket, stat, subprocess, tempfile, time, unittest, urllib.error, urllib.request, zipfile
ROOT=pathlib.Path(__file__).resolve().parents[1]
PHP=os.environ.get('OPENMPS_TEST_PHP','php')
PHP_ARGS=json.loads(os.environ.get('OPENMPS_TEST_PHP_ARGS','[]'))
class ApiTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp=tempfile.TemporaryDirectory();cls.site=pathlib.Path(cls.temp.name)/'site';shutil.copytree(ROOT,cls.site,ignore=shutil.ignore_patterns('.git','__pycache__'))
        cls.env=os.environ.copy();cls.env['OPENMPS_API_TOKEN_FILE']=str(cls.site/'api-tokens.php');cls.env['TMPDIR']=cls.temp.name
        def token(label,scopes):
            output=subprocess.check_output([PHP,*PHP_ARGS,str(cls.site/'tools/api_token.php'),'create',label,scopes],env=cls.env,text=True)
            return re.search(r'Token ID: (\w+)',output)[1],re.search(r'Token \(shown once\): (omps_\S+)',output)[1]
        cls.id,cls.token=token('integration','read,publish,recover');cls.read_id,cls.read_token=token('reader','read')
        with socket.socket() as s:s.bind(('127.0.0.1',0));cls.port=s.getsockname()[1]
        cls.url=f'http://127.0.0.1:{cls.port}/';cls.log=open(pathlib.Path(cls.temp.name)/'server.log','w+')
        cls.process=subprocess.Popen([PHP,*PHP_ARGS,'-S',f'127.0.0.1:{cls.port}','-t',str(cls.site),str(cls.site/'index.php')],env=cls.env,stdout=cls.log,stderr=cls.log)
        for _ in range(100):
            try:cls.request('capabilities',auth=False);break
            except OSError:time.sleep(.05)
        else:raise RuntimeError('PHP server did not start')
    @classmethod
    def tearDownClass(cls):cls.process.terminate();cls.process.wait(timeout=10);cls.log.flush();cls.log.seek(0);print(cls.log.read()[-6500:]) if os.environ.get('OPENMPS_TEST_DEBUG') else None;cls.log.close();cls.temp.cleanup()
    @classmethod
    def request(cls,action,body=None,auth=True,token=None,headers=None,params=''):
        h={'Authorization':'Bearer '+(token or cls.token)} if auth else {}
        if headers:h.update(headers)
        request=urllib.request.Request(cls.url+'api/v1.php?action='+action+params,data=body,headers=h)
        try:
            with urllib.request.urlopen(request,timeout=20) as response:return response.status,response.read(),dict(response.headers)
        except urllib.error.HTTPError as e:return e.code,e.read(),dict(e.headers)
    @staticmethod
    def archive(key='demo',version='1.0',extra=None):
        out=io.BytesIO()
        with zipfile.ZipFile(out,'w',zipfile.ZIP_DEFLATED) as z:
            z.writestr('pack_info.dynx',f'PackName: {key}\nPackVersion: {version}\nDcFileVersion: 12.5.0\n')
            z.writestr('vehicle_car.dynx',f'Name: Car\nModel: {key}:model.obj\n')
            z.writestr(f'assets/{key}/model.obj','v 0 0 0\nv 1 0 0\nv 0 1 0\nf 1 2 3\n')
            for name,value in (extra or {}).items():z.writestr(name,value)
        return out.getvalue()
    @classmethod
    def publish(cls,key,version,body=None,token=None,digest=None,access_key='legacy'):
        body=body if body is not None else cls.archive(key,version)
        return cls.request('publish',body,token=token,headers={'Content-Type':'application/zip','X-OpenMPS-Pack-Key':key,'X-OpenMPS-Pack-Version':version,'X-OpenMPS-SHA256':digest or hashlib.sha256(body).hexdigest(),'X-OpenMPS-Access-Key':access_key})
    def test_authentication_scopes_and_capabilities(self):
        self.assertEqual(self.request('capabilities',auth=False)[0],401)
        self.assertEqual(self.request('capabilities',token='omps_'+'x'*43)[0],401)
        code,body,_=self.request('capabilities');self.assertEqual(code,200);data=json.loads(body);self.assertEqual(data['api_version'],1);self.assertEqual(data['public_url'],self.url);self.assertTrue(data['immutable_versions']);self.assertEqual(set(data['scopes']),{'read','publish','recover'})
        self.assertEqual(self.publish('scopetest','1.0',token=self.read_token)[0],403)
    def test_publish_download_idempotency_and_game_resource_routes(self):
        body=self.archive('roundtrip','1.0');code,result,_=self.publish('roundtrip','1.0',body);self.assertEqual(code,200,result);receipt=json.loads(result)['publication']
        code,client,headers=self.request('client',params='&pack_key=roundtrip&pack_version=1.0');self.assertEqual(code,200);self.assertEqual(hashlib.sha256(client).hexdigest(),receipt['client_sha256']);self.assertEqual(headers['X-OpenMPS-SHA256'],receipt['client_sha256'])
        with zipfile.ZipFile(io.BytesIO(client)) as z:
            self.assertEqual(set(z.namelist()),{'pack_info.dynx','vehicle_car.dynx','MpsRepositories.json','README_INSTALLATION.txt'})
            config=json.loads(z.read('MpsRepositories.json'));self.assertEqual(config['mainUrl'],self.url);self.assertEqual(config['accessKey'],'legacy');self.assertNotIn(self.token,str(config))
        code,result,_=self.publish('roundtrip','1.0',body);self.assertEqual(code,200,result);self.assertTrue(json.loads(result)['publication']['idempotent'])
        changed=self.archive('roundtrip','1.0',{'assets/roundtrip/new.txt':'different'});self.assertEqual(self.publish('roundtrip','1.0',changed)[0],409)
        self.assertEqual(self.publish('roundtrip','2.0')[0],200)
        code,packs,_=self.request('packs');self.assertEqual(code,200);self.assertTrue(any(p['pack_key']=='roundtrip' and p['pack_version']=='2.0' for p in json.loads(packs)['packs']))
        version=base64.b64encode(b'1.0').decode()
        with urllib.request.urlopen(self.url+f'get/1.3.0/roundtrip/{version}/assets/roundtrip/model.obj') as response:self.assertIn(b'v 1 0 0',response.read())
        with urllib.request.urlopen(self.url+f'get/1.3.0/roundtrip/{version}') as response:
            with zipfile.ZipFile(io.BytesIO(response.read())) as z:self.assertFalse(z.read('roundtrip.desc').startswith(b'Id='))
    def test_bad_archives_are_rejected_without_partial_version(self):
        cases=[('badpath',{'../escape.txt':'bad'}),('badlayout',{'models/car.obj':'private'}),('badscript',{'assets/badscript/evil.php':'<?php'}),('badcase',{'ASSETS/badcase/model.obj':'duplicate'}),('suffixscript',{'assets/suffixscript/evil.php.png':'bad'})]
        for key,extra in cases:
            with self.subTest(key=key):
                code,body,_=self.publish(key,'1.0',self.archive(key,'1.0',extra));self.assertEqual(code,422,body);self.assertFalse((self.site/'packs'/key/'1.0').exists());self.assertFalse(list((self.site/'packs'/key).glob('.api-stage-*')))
        self.assertEqual(self.publish('mismatch','1.0',self.archive('different','1.0'))[0],422)
        self.assertEqual(self.publish('checksum','1.0',digest='0'*64)[0],422)
        self.assertEqual(self.publish('badkey','1.0',access_key='not-allowed')[0],422)
        self.assertEqual(self.publish('../outside','1.0')[0],400)
        self.assertEqual(self.publish('version','../outside')[0],400)
        self.assertFalse((self.site/'packs'/'escape.txt').exists())
    def test_symlinks_and_resource_escape_are_rejected(self):
        body=io.BytesIO(self.archive('links','1.0'))
        with zipfile.ZipFile(body,'a') as z:
            link=zipfile.ZipInfo('assets/links/link.obj');link.create_system=3;link.external_attr=(stat.S_IFLNK|0o777)<<16;z.writestr(link,'../../config.php')
        self.assertEqual(self.publish('links','1.0',body.getvalue())[0],422)
        self.assertFalse((self.site/'packs/links/1.0').exists())
        code,result,_=self.publish('paths','1.0');self.assertEqual(code,200,result)
        version=base64.b64encode(b'1.0').decode()
        with urllib.request.urlopen(self.url+f'get/1.3.0/paths/{version}/assets/paths/sub/../model.obj') as response:self.assertIn(b'v 1 0 0',response.read())
        with self.assertRaises(urllib.error.HTTPError) as caught:urllib.request.urlopen(self.url+f'get/1.3.0/paths/{version}/../../../config.php')
        self.assertEqual(caught.exception.code,400)
    def test_editable_recovery_requires_its_scope_and_contains_original_resources(self):
        code,result,_=self.publish('recoverable','1.0');self.assertEqual(code,200,result)
        params='&pack_key=recoverable&pack_version=1.0'
        self.assertEqual(self.request('source',params=params,auth=False)[0],401)
        self.assertEqual(self.request('source',params=params,token=self.read_token)[0],403)
        code,body,_=self.request('capabilities',token=self.read_token);self.assertFalse(json.loads(body)['recovery_allowed']);self.assertEqual(json.loads(body)['scopes'],['read'])
        code,body,headers=self.request('source',params=params);self.assertEqual(code,200,body);self.assertEqual(hashlib.sha256(body).hexdigest(),headers['X-OpenMPS-SHA256'])
        self.assertEqual(headers['X-OpenMPS-Pack-Key'],'recoverable')
        with zipfile.ZipFile(io.BytesIO(body)) as z:
            self.assertIn('assets/recoverable/model.obj',z.namelist());self.assertIn('pack_info.dynx',z.namelist())
            self.assertNotIn('MpsRepositories.json',z.namelist());self.assertFalse(any(n.endswith('.zip') or n.endswith('.desc') for n in z.namelist()))
        root=self.site/'packs/recoverable/1.0';(root/'encrypted.part').write_bytes(b'encrypted')
        self.assertEqual(self.request('source',params=params)[0],422);(root/'encrypted.part').unlink()
        (root/'escape.obj').symlink_to(self.site/'config.php');self.assertEqual(self.request('source',params=params)[0],422)
    def test_token_revocation_and_private_state(self):
        output=subprocess.check_output([PHP,*PHP_ARGS,str(self.site/'tools/api_token.php'),'create','revocable','read'],env=self.env,text=True)
        token=re.search(r'Token \(shown once\): (omps_\S+)',output)[1];id=re.search(r'Token ID: (\w+)',output)[1]
        self.assertEqual(self.request('packs',token=token)[0],200)
        subprocess.check_call([PHP,*PHP_ARGS,str(self.site/'tools/api_token.php'),'revoke',id],env=self.env,stdout=subprocess.DEVNULL)
        self.assertEqual(self.request('packs',token=token)[0],401)
        self.assertNotIn(token,(self.site/'api-tokens.php').read_text())
        for path in ['api-tokens.php','var/openmps-api/unknown.json']:
            with self.assertRaises(urllib.error.HTTPError) as caught:urllib.request.urlopen(self.url+path)
            self.assertEqual(caught.exception.code,404)
    def test_legacy_client_builder_never_embeds_root_model_or_texture_extras(self):
        folder=self.site/'legacy-fixture';folder.mkdir();(folder/'pack_info.dynx').write_text('PackName: legacy\nDcFileVersion: 12.5.0\n');(folder/'model.obj').write_text('private');(folder/'texture.png').write_bytes(b'private');(folder/'vehicle_car.dynx').write_text('Name: Car\n')
        code='require $argv[1]; echo mps_generate_client_zip($argv[2],"legacy","1.0",["model.obj"=>$argv[2]."/model.obj","texture.png"=>$argv[2]."/texture.png"],"http://localhost/");'
        client=subprocess.check_output([PHP,*PHP_ARGS,'-r',code,str(self.site/'pack_manager.php'),str(folder)],env=self.env,text=True)
        with zipfile.ZipFile(client) as z:self.assertNotIn('model.obj',z.namelist());self.assertNotIn('texture.png',z.namelist());self.assertIn('vehicle_car.dynx',z.namelist())
if __name__=='__main__':unittest.main()
