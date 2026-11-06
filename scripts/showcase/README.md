# Static showcase (aipbx.bid/demo)

The portal itself, saved as static pages with a made-up company, so visitors can look around
without installing anything. No PHP, database or Asterisk behind it: `showcase.js` answers the
pages' API calls from the answers recorded at export, makes the dashboard and call-centre views
move with a small call simulator, and lets nothing leave the browser (forms that save do nothing,
no phone or chat connections). English, Turkish and German.

Rebuild after a release (developer tool, never on a server):

1. A test install in an LXD container `aipbx-showcase` (fresh `install.sh` of the release).
2. `scripts/showcase/build.sh` — reseeds it (`seed.php`: invented names, fictional numbers
   +1 555 01xx / +44 7700 900xxx, documentation address ranges), exports every menu page in the three
   languages (`export.mjs`, headless Chrome), copies the assets with the paths rewritten under
   `/demo/<lang>/` (`assets.py`), applies the few hand edits (`fixup.py`) and adds `showcase.js` and
   the language chooser `index.html`. Output: `out/` (about 40 MB).
3. Copy `out/` to `/var/www/aipbx.bid/public_html/demo/` on the web server.

`build.sh` expects to run next to these files with `node`, `python3`, Chrome and `lxc`.
