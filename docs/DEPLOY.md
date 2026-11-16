# Putting OneDOST online (AlwaysData, free plan)

OneDOST runs online without a database server: everything you save lives in
one file, `database/database.sqlite`, next to the app. Your uploaded photos
live in `storage/app/private`. Those two, plus the `.env` file, are your
data — **never delete the app folder on the host.**

AlwaysData's screens change from time to time; where this guide names a
setting, look for it in the admin panel under a similar name.

---

## 1. On your computer, once

1. **Make your login.** The app is behind a login now, with no sign-up page.
   In PowerShell, in `C:\Herd\OneDOST`:

   ```powershell
   php artisan app:user you@example.com --name="Your Name"
   ```

   It asks for a password twice (at least 10 characters). This login is
   copied to the host with your data, so it works there too.

2. **Sign up at alwaysdata.com** for the free plan. Your account name is in
   your site's address: `https://ACCOUNT.alwaysdata.net`.

3. **Make the first upload.** Use your real address:

   ```powershell
   php artisan app:package --first --url=https://ACCOUNT.alwaysdata.net
   ```

   This builds the pages, fetches the libraries the app needs (without the
   testing tools), copies your records into a SQLite database, and writes
   a `.env` for the host with a new secret key. The result is one zip in
   `C:\Herd\OneDOST\build\`, named like `onedost-2026-11-16-164022-first.zip`.

   **Keep that zip private** — it holds your records and the app's key.
   Delete it once the site works.

## 2. On AlwaysData

1. **PHP version.** In the admin panel's environment / languages settings,
   choose PHP **8.4** (8.3 or newer works).

2. **Upload and unzip.** With SSH (the admin panel shows its address,
   usually `ssh-ACCOUNT.alwaysdata.net`), from PowerShell:

   ```powershell
   scp build\onedost-…-first.zip ACCOUNT@ssh-ACCOUNT.alwaysdata.net:
   ssh ACCOUNT@ssh-ACCOUNT.alwaysdata.net
   ```

   then, on the server:

   ```bash
   unzip onedost-…-first.zip -d onedost
   rm onedost-…-first.zip
   cd onedost && php artisan optimize
   ```

   *Without SSH:* unzip the file on your computer and upload the whole
   `onedost` folder by SFTP or FTP (WinSCP or FileZilla) into your home
   folder. It's about 8,000 files, so give it a while.

3. **Point the site at the `public` folder.** In the site's settings, set it
   to **PHP**, with the root directory **`/onedost/public`**, not
   `/onedost`. Turn on **force HTTPS** if offered.

4. **Open `https://ACCOUNT.alwaysdata.net`** and log in.

5. **Check that nothing private shows.** Open
   `https://ACCOUNT.alwaysdata.net/.env`. It must say **Not Found**. If you
   see text instead, the site's root is `/onedost` instead of
   `/onedost/public`: fix it right away, then make a new key (step "A new
   secret key" below).

## 3. Keeping your data safe

- **Back up.** In the app, **Download backup** (in the side menu) gives you
  one zip with the database and your photos. Do it now and then — after
  importing a DTR, after a batch of liquidations. Free hosting can go away;
  your backup doesn't.
- **Restoring a backup:** put its `database/database.sqlite` and
  `storage/app/private` folder back into `onedost/` on the host (replacing
  what's there).
- **Forgot the password?** Over SSH: `cd onedost && php artisan app:user you@example.com`.
  It asks for a new one and signs out every device.

## 4. Updating the app later

After changes on your computer:

```powershell
php artisan app:package
```

This zip has **no** `.env`, database or photos, so it can't overwrite yours.
Upload it and unzip it **over** the existing folder (`-o` replaces the
app's files, and leaves your data alone):

```bash
unzip -o onedost-….zip -d onedost
rm onedost-….zip
cd onedost && php artisan migrate --force && php artisan optimize
```

`migrate` adds any new tables or columns; it never deletes your records.
(The live site refuses `migrate:fresh` and `db:wipe`.)

Never use `--first` again once the site is live: its database would replace
everything saved online since.

## 5. If something goes wrong

- **"500 Server Error":** the error is in `onedost/storage/logs/` (one file a
  day). Over SSH: `tail -n 50 onedost/storage/logs/laravel-*.log`.
- **Changed `.env`?** Run `php artisan optimize` again so the app reads it.
- **A new secret key** (if `.env` was ever visible): over SSH,
  `cd onedost && php artisan key:generate --force && php artisan optimize`.
  You'll have to log in again; your records are unaffected.
- **"The page had expired":** the page sat open past the 2-hour session;
  just do it again.
