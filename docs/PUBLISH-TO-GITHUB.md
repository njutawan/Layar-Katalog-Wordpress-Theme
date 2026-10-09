# Publish this source bundle to GitHub

This folder is a ready-to-push source tree. The GitHub connector is not available in the current Arena session, so the remote repository must be created/pushed from your own GitHub account.

1. Download and extract `layar-katalog-wordpress-source.zip`.
2. On GitHub, create a **public empty repository** named `layar-katalog-wordpress` (or your preferred name). Do not initialize another README or license because this source tree already has both.
3. From a terminal in the extracted `layar-katalog-wordpress` directory, run:

```sh
git init -b main
git add .
git commit -m "Initial public release"
git remote add origin https://github.com/YOUR-USERNAME/layar-katalog-wordpress.git
git push -u origin main
```

Use the GitHub credential manager or an SSH remote if prompted; do not paste a personal access token into this chat or commit it into the repository.

The root README explains installation and requirements. The repository includes both the theme and its companion Core plugin, GPL-2.0-or-later metadata, and third-party MIT notices.