# migration/sql/

Generated SQL lives here and is **gitignored** because it contains real customer
lead data (PII). Do not commit these files.

To (re)generate the CRM data import from a fresh CRM dump:

```bash
python3 migration/build_crm_import.py /path/to/dbg8w2f3gdgcjw.sql migration/sql/crm_data_import.sql
```

Then run the full Phase 1 migration:

```bash
bash migration/run_phase1.sh local   # or: prod
```
