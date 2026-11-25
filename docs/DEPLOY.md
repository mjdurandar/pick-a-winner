# How to deploy the WIN app

Run every command below on the Mac, inside the project folder
(`/Users/mjdurandar/Code/pick-a-winner`). You do not need to log in to the server.

Always go **staging first, then production**.

---

## Step 1 — Save your work to GitHub

```bash
git add -A
```
Picks up every file you changed.

```bash
git commit -m "fix: what you changed"
```
Saves those changes with a short message.

```bash
git push origin master
```
Sends them to GitHub. The server pulls its code from GitHub, so nothing can be
deployed until it is pushed.

---

## Step 2 — Preview the deploy (changes nothing)

```bash
scripts/deploy.sh staging --check
```
Shows which commits would go up and which migrations would run. It only looks.
Use `production` instead of `staging` to preview the live site.

---

## Step 3 — Deploy to staging

```bash
scripts/deploy.sh staging
```
It asks `Deploy to staging? [y/N]`. Type `y` and press Enter.

When it ends with `Deployed ... to staging`, open the staging site and click
around the pages you changed:
https://white-rail-267402.hostingersite.com

---

## Step 4 — Deploy to production

```bash
scripts/deploy.sh production
```
It asks you to type the word `production`. This is the live site, so only do
this after staging looks right.

When it ends with `Deployed ... to production`, open the live site and check it:
https://win.adventureentertainment.com

---

## What the deploy command does for you

1. Stops if you have uncommitted work, or have not pushed.
2. Builds the front-end on the Mac (the server has no Node).
3. Uploads the new files. It does not delete the old ones, so anyone with the
   app open (a host in the middle of a draw) keeps working.
4. Pulls the same commit on the server.
5. Installs PHP packages, only if they changed.
6. Runs new migrations, one at a time.
7. Clears the caches and restarts the queue.
8. Loads the site to make sure it answers.

---

## Migrations that drop or delete data

The script never runs these on its own. It lists them as **HELD BACK**.

To run one on purpose, name it:

```bash
scripts/deploy.sh staging --allow=2026_08_19_000000_drop_is_hidden_from_locations_table
```
It asks you to type `drop` to confirm. Only do this when you are sure the data
is no longer needed.

Held back as of 25 Nov 2026:

| Migration | Staging | Production |
|---|---|---|
| `2026_08_18_000000_drop_mailchimp_import_logs_and_auto_import_config` | held | already run |
| `2026_08_19_000000_drop_is_hidden_from_locations_table` | held | already run |
| `2026_11_03_000000_drop_sms_tables` | held | held |
| `2026_11_04_000000_drop_sponsors_table` | held | held |

---

## Old or new Pick a Winner screens

The redesigned Pick a Winner (what hosts see on the night) is switched on per
site, so the rest of the app can go live while the new draw waits for approval.

| Site | Setting in that site's `.env` | Hosts see |
|---|---|---|
| Staging | `PICK_A_WINNER_NEW_UI=true` | the new screens |
| Production | not set (or `false`) | the old screens |

To switch a site over once the new draw is approved, add this line to its
`.env` on the server, then clear the config cache:

```bash
PICK_A_WINNER_NEW_UI=true
```

```bash
/opt/alt/php82/usr/bin/php artisan config:clear
```
(Use `php84` on staging.) No deploy is needed. Remove the line to go back.

---

## If a deploy goes wrong

The script stops and prints the exact commands that put the previous version
back. Copy them from the terminal. They restore the code and the front-end.
Migrations that already ran are not undone.

---

## Logging in to the server (only if you need to look at something)

```bash
ssh win-hostinger
```
Logs in with the key on this Mac (`~/.ssh/win_hostinger`). No password.

```bash
cd /home/u717531173/domains/white-rail-267402.hostingersite.com/public_html
```
The staging folder.

```bash
cd /home/u717531173/domains/win.adventureentertainment.com/public_html
```
The production folder.

```bash
tail -50 storage/logs/laravel.log
```
The latest errors from the app.

```bash
cat storage/logs/deploy.log
```
Every deploy: when, from which commit, to which commit.

Each site uses its own PHP. If you ever run artisan by hand, use the right one:

| Site | PHP |
|---|---|
| Staging | `/opt/alt/php84/usr/bin/php` |
| Production | `/opt/alt/php82/usr/bin/php` |

---

## Do not run these on the server

| Command | Why not |
|---|---|
| `php artisan migrate` | Runs the held-back migrations and drops tables |
| `php artisan key:generate` | Logs everyone out and breaks the Google connection |
| `php artisan queue:flush` | Throws away the failed jobs |
| the tinker line that truncates the `jobs` table | Throws away work still waiting to run |
| `rsync --delete` of `public/build` | Breaks pages people have open |

---

## Passwords

None are kept in this file, and none are needed to deploy. The SSH and database
passwords live in hPanel.
