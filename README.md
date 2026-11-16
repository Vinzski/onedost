# onedost

A portal of operations for DOST Region IX, built with Laravel, Inertia and Vue:
airline booking requests, cash advances, travel liquidations and
reimbursements, leave applications (CSC Form No. 6) and the Daily Time Record
— each filling in the region's own forms.

- **The DTR page on its own** — [`standalone/dtr/`](standalone/dtr/README.md):
  one page, no server or database. Import your DTR's PDF, note why each blank is
  blank, save your records as a file. Host it free on Netlify or GitHub Pages.
- **Running OneDOST** — PHP 8.4, Composer and Node; see [`CLAUDE.md`](CLAUDE.md)
  for the stack, conventions and commands (`composer run dev`, `php artisan test`).
- **Putting it online** — [`docs/DEPLOY.md`](docs/DEPLOY.md).
