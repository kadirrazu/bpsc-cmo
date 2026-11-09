# Update Instructions

Keep all versioned `UPDATE-v*.txt` delivery instructions in this directory. Do not place them in the project root.

Detailed feature notes and verification reports remain in the parent `docs/` directory. The project's fixed root `readme.md` remains unchanged during releases.

For installations containing older root update files, run:

```bash
php artisan cmo:organize-update-notes
```

This moves legacy notes here, removes only byte-identical duplicate root copies and preserves conflicting versions under separate filenames.
