# Static showcase (aipbx.bid/demo)

The portal itself, saved as static pages with a made-up company, so visitors can look around
without installing anything. No PHP, database or Asterisk behind it: `showcase.js` answers the
pages' API calls from selected read-only answers recorded at export. A small simulator moves the
dashboard and call-centre views. The browser can load demo files and navigate the screens.
Forms that would save, live phone and chat connections, and outbound API requests are blocked.
The demo is available in English, Turkish and German.

Rebuild after a release (developer tool, never on a server):

1. A disposable test install in LXD (fresh `install.sh` of the release). The defaults are
   `SHOWCASE_CONTAINER=aipbx-showcase` and `SHOWCASE_BASELINE=/root/before-seed.sql` inside
   that container. Use a clean database snapshot from the same release as the installed portal.
2. `scripts/showcase/build.sh` — reseeds it (`seed.php`: invented names, fictional numbers
   +1 555 01xx / +44 7700 900xxx, documentation address ranges), exports every menu page in the three
   languages (`export.mjs`), copies the assets with the paths rewritten under
   `/demo/<lang>/` (`assets.py`), applies the few hand edits (`fixup.py`) and adds `showcase.js` and
   the language chooser `index.html`. Output: `out/` (about 40 MB).
3. Check the exported navigation and confirm that a form submit leaves the static demo unchanged.
   Publish only the exported files in `out/` to `/var/www/aipbx.bid/public_html/demo/` on the web server.

`build.sh` expects to run next to these files with `node`, `python3` and `lxc`.
