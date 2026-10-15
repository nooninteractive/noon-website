# Noon Interactive website

Production-ready web files for `nooninteractive.com`. The first hero slide uses the new generated studio image; there are three slides in total. Games and Development list Pulse, and Servers lists Desterrados.

## Publish from cPanel

1. Open **Git Version Control** and select the repository cloned from `https://github.com/nooninteractive/website.git`.
2. Prefer a checkout outside the public directory, for example `/home/noonint1/repositories/noon-website`. The document root stays `/home/noonint1/public_html`.
3. Under **Manage → Pull or Deploy**, choose **Update from Remote**, then **Deploy HEAD Commit**.
4. The checked-in `.cpanel.yml` copies only the website files to `/home/noonint1/public_html`, including `.htaccess`. Existing files that are not part of this release are not deleted. If the repository itself is already the document root, the deployment copy is skipped because the files are already in place.

Before the first production deployment, keep a backup through cPanel. No database import or credential replacement runs automatically.

## Accounts and Pulse uploads

The public pages work without a database. Accounts, comments and Pulse uploads need PHP 8.2+, the required extensions and configured MySQL/storage:

- Import `deployment-support/noon-schema.sql` for the studio accounts/comments.
- Copy `deployment-support/noon-config.example.php` to `/home/noonint1/noon-config.php`, outside `public_html`, and set actual database credentials plus a random secret.
- Import `deployment-support/pulse-schema.sql` for Pulse.
- Copy `deployment-support/pulse-config.example.php` to `/home/noonint1/pulse-config.php`. Use `https://nooninteractive.com` as the origin, set the actual database credentials, generate a random secret, and set private writable storage such as `/home/noonint1/pulse-storage`.
- Pulse audio uploads need `fileinfo`, `pdo_mysql`, `mbstring`, PHP upload limits sufficient for 25 MB audio plus 5 MB cover images, and a private storage directory outside all public document roots.

Do not commit actual credentials, private media storage, database dumps or session data. The SQL and examples are setup materials; deployment does not copy them to the public directory.

This repository has been checked locally. Publishing and the live database configuration remain actions to complete in cPanel.
