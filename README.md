# Seal Chat Web

The web interface for [Seal Chat](https://github.com/jeffbristow/sealchat): put this folder on your own PHP hosting, open it on your phone, and chat through the Seal Chat running on your computer. Your PGP key never leaves your computer; the website only passes sealed messages along.

Install instructions, settings and the version history are in [README.txt](README.txt), and on [sealpgp.com/chat](https://sealpgp.com/chat/#web).

- Needs PHP 8 over https. No database.
- `config.php` holds only the defaults. Your own settings live in the data folder outside `public_html`.
- Installed sites update themselves from signed packages (admin.php → Updates). The packages are published in [sealchat-updates](https://github.com/jeffbristow/sealchat-updates).

## Releasing (maintainer)

1. Bump `SEALCHAT_WEB_VERSION` in `lib.php` and add the notes to `README.txt`. Commit, tag `v<version>`, and push the tag.
2. Build the package from the tag:
   `git -c core.autocrlf=false archive --format=zip --prefix=SealChat-Web-<version>/ -o SealChat-Web-<version>.zip v<version>`
3. Sign it with the Seal Chat update key:
   `node ../sealchat/build/sign-web-update.js SealChat-Web-<version>.zip <path to update-signing-key.pem>`
4. Publish the zip and its `.sig` in sealchat-updates (the `web` block of `update.json`).

## License

GPL-3.0, like Seal Chat. Bundled: jsQR (Apache-2.0) and Emojibase data (MIT); their licenses are in `vendor/`.
