# PHPCLI-Commits

Generate commit range reports and release notes for GitHub and GitLab repositories.

## Installation

```shell
git clone https://github.com/jschwind/phpcli-commits.git
cd phpcli-commits
chmod +x commits.sh
```

Add `commits.sh` to your PATH or create a symlink, e.g., on Arch/Manjaro Linux via `~/.bashrc`:

```shell
sudo ln -s $(pwd)/commits.sh /usr/local/bin/commits
```

## Usage

```shell
commits [OUTPUT_FILE] [--config=CONFIG_FILE]
```

Generate commit range reports with Git repository parameters.

### Options
* `OUTPUT_FILE`: Optional. Output file name (default: `commits.txt`).
* `--config`: Optional. Configuration file path (default: `git.json`).

## Configuration

Create a `git.json` file with the following structure:

```json
{
  "provider": "github",
  "owner": "username",
  "repo": "repository-name",
  "fromTag": "v1.0.0",
  "toTag": "v1.1.0",
  "github_token": "ghp_your_token_here",
  "gitlab_token": "glpat_your_token_here",
  "gitlab_host": "https://gitlab.example.com",
  "stepTag": false
}
```

### Configuration Parameters
* `provider`: Repository provider (`github` or `gitlab`).
* `owner`: Repository owner/organization name.
* `repo`: Repository name.
* `fromTag`: Starting tag for comparison (supports keywords: `first` for oldest tag, or version prefix like `1.0`).
* `toTag`: Ending tag for comparison (supports keywords: `current`/`latest` for newest tag, or version prefix like `1.1`).
* `github_token`: Optional. GitHub personal access token.
* `gitlab_token`: Optional. GitLab personal access token.
* `gitlab_host`: Optional. GitLab host URL for self-hosted instances.
* `stepTag`: Optional. Generate step-by-step reports between consecutive tags (default: `false`).

### Tag Keywords
* `fromTag`:
    * `"first"` → First (oldest) tag in repository
    * `""` → First (oldest) tag in repository
    * `"1.0"` → Earliest tag matching version prefix
* `toTag`:
    * `"current"` → Latest (newest) tag in repository
    * `"latest"` → Latest (newest) tag in repository
    * `""` → Latest (newest) tag in repository
    * `"1.1"` → Latest tag matching version prefix

## Examples

### Basic Usage
```shell
commits
commits changelog.txt
commits release-notes.txt --config=production.json
```

### Tag Keywords Examples
```json
{
  "fromTag": "first",
  "toTag": "current"
}
```

```json
{
  "fromTag": "",
  "toTag": "latest"
}
```

```json
{
  "fromTag": "1.0",
  "toTag": "2.0"
}
```

### Step Mode Example

Set `stepTag: true` in configuration to generate multiple files for consecutive tag ranges:

```shell
commits multi-release
```

Generates:
- `multi-release.v1.0.0..v1.1.0.txt`
- `multi-release.v1.1.0..v1.2.0.txt`
- `multi-release.v1.2.0..v1.3.0.txt`