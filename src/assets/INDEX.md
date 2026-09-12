# assets/ — page CSS and JS

| File | What |
| :-- | :-- |
| `cc.css` | Styles, all scoped under `.cc-root`. |
| `cc-model.js` | Snapshot → session model and formatting. No DOM. |
| `cc-view.js` | Takes snapshots from nchan (or `state.php`), renders the page, keeps each viewer's toggles. |
| `cc-dash.js` | Dashboard tile renderer (`CCDash.render`); nchan `connections_dash`, fallback `summary.php`. |
| `cc-dash.css` | Dashboard tile styles, all scoped under `.cc-dash`. |
