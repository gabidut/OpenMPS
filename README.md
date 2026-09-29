# OpenMPS
<img src="logo.png">

OpenMPS is an open-source, lightweight implementation of the DynamX Mod Protection System (MPS) server, in addition of the official [DynamX MPS](https://mps.dynamx.fr/).

Built for Minecraft 1.12.2 and DynamX, OpenMPS enables server administrators and modders to securely protect, encrypt, and stream vehicle, prop, and armor packs directly to players' Minecraft clients on-the-fly.

- **GitHub Repository**: [https://github.com/gabidut/OpenMPS](https://github.com/gabidut/OpenMPS)

---

## Requirements

- **PHP 7.4+** or **PHP 8.x** (CLI + Built-in web server or Apache/Nginx)
- **Required PHP Extensions**:
  - `openssl` (AES-128 encryption)
  - `zip` / `ZipArchive` (pack extraction and packaging)
  - `json`
- **Recommended PHP settings** (in `php.ini`):
  - `upload_max_filesize = 512M`
  - `post_max_size = 512M`
  - `memory_limit = 512M`

---

## Quick Start

### ⚡ One-Command Installation

Run this single command in your target folder to download and install OpenMPS:

```bash
curl -sSL https://raw.githubusercontent.com/gabidut/OpenMPS/main/installer.php -o installer.php && php installer.php
```

Or for fully automated setup with default settings (Port `8081`, credentials `admin`/`admin`):

```bash
curl -sSL https://raw.githubusercontent.com/gabidut/OpenMPS/main/installer.php -o installer.php && php installer.php --defaults
```

<details>
<summary><strong>Alternative Installation Methods</strong></summary>

**Windows (PowerShell)**:
```powershell
curl.exe -sSL https://raw.githubusercontent.com/gabidut/OpenMPS/main/installer.php -o installer.php; php installer.php
```

**Git Clone One-Liner**:
```bash
git clone https://github.com/gabidut/OpenMPS.git && cd OpenMPS && php installer.php
```

</details>

The installer automatically verifies environment prerequisites, sets up directories (`packs/`, `loader/`, `1.3.0/`, `tools/`), downloads core files from [https://github.com/gabidut/OpenMPS](https://github.com/gabidut/OpenMPS), and generates `config.php`.

### 2. Starting the Server

- **Windows**:
  Double-click `start_server.bat` or run:
  ```cmd
  php -d upload_max_filesize=512M -d post_max_size=512M -d memory_limit=512M -S 0.0.0.0:8081 index.php
  ```

- **Linux / macOS**:
  ```bash
  chmod +x start_server.sh
  ./start_server.sh
  ```

Access the dashboard at `http://localhost:8081/index.php`.

Default credentials:
- **Username**: `admin`
- **Password**: `admin`

## CLI Pack Helper

A helper script is available in `tools/pack_helper.php`:

- **Pack a directory**:
  ```bash
  php tools/pack_helper.php pack <folder> [output.zip]
  ```
- **Generate `.desc` file**:
  ```bash
  php tools/pack_helper.php desc <pack_folder> [output.desc] [http://localhost:8081/]
  ```
- **Encrypt an existing `.desc`**:
  ```bash
  php tools/pack_helper.php encrypt-desc <input.desc> [output.desc] [repo_url]
  ```
- **Validate an access key**:
  ```bash
  php tools/pack_helper.php test-key legacy
  ```
