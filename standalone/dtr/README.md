# DTR — your Daily Time Record, on one page

Import your DTR's PDF, see every blank, note why. No server, no database, no
login: your records stay on your device, and **Save file** gives you a plain
text file (open it in Notepad if you like) that is your backup.

## Using it

- **Something off today?** No internet, biometric down, forgot to time in —
  pick what's blank and why, and save. It doesn't need your DTR yet.
- **Import DTR** — the PDF from the DTR system. Blanks without a reason show a
  yellow **?**; tap one to say why. A note can cover several days.
- **Print explanations** — one page of the month's blanks and reasons, for the
  In-Charge. Choose **Save as PDF** as the printer to get a PDF.
- **Save file** — downloads `dtr-records-<date>.json`. Do it now and then: if
  the browser's data is cleared, this file is how your notes come back. The
  button turns yellow when there are changes since your last saved file.
- **Open file** — loads a saved file. If the browser already has records, you
  choose: *add the file to them* (each note once) or *replace them*.

Each browser keeps its own copy. To use your phone and your computer, save the
file on one and open it on the other.

## Putting it online (free)

The page is just the files in this folder — nothing to install.

- **On your computer only:** double-click `index.html`.
- **Online, so your phone can use it:** sign up free at netlify.com, open
  **app.netlify.com/drop**, and drag this `dtr` folder onto it. You get an
  `https://….netlify.app` address. (GitHub Pages or Cloudflare Pages work too.)
- **On your phone:** open that address, then **Add to Home Screen**. After the
  first visit it opens even with no internet — handy on the day the internet
  is down.

Only the page goes online; your records never do.

## For developers

Plain files, no build step: `index.html`, `js/` (reader, records, app — Vue 3
Options API with template strings), `css/app.css`, and `vendor/` (Vue,
Bootstrap and Remix Icon copied in, so the page works offline). `sw.js` keeps
the offline copy; bump its `CACHE` name when the files change.

`js/reader.js` is the browser twin of `app/Services/Dtr/DtrPdfReader.php`, and
`js/record.js` follows `DtrService`'s day statuses: change both. Tests:
`npm run test:dtr` (Node's test runner; they also render the page).
