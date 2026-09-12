# scripts/ — collector

| File | What |
| :-- | :-- |
| `collector.php` | Daemon. Writes `/tmp/unraid-connections/state.json` every 5 s. |
| `history-flush.php` | Copy `history.db` to flash now (used by `event/stopping`). |
| `diagnostics.sh` | Read-only diagnostic bundle for a support request (`HELP.md`). |
| `rc.unraid-connections` | `start`, `stop`, `restart`, `status` for the collector. |
