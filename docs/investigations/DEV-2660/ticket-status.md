<!-- ticket-status -->
**Ticket:** [DEV-2660](https://insightlpr-team.atlassian.net/browse/DEV-2660)
**Phase:** Implement done
**Done:** intake · Ultra-Prep · Ultra-Plan · Provision · Implement
**Next:** Review
**Gate:** CLEAN
**Open questions:** none. #25 is green. STOP_SWAP=false. FINAL on clarity.hotlist is off. `{floor}` stays KEEP.
**Branch path:** main→main (First Glance **42078**)
**PR:** #470 **DRAFT**
**Provisioned:** true — fleet Path A site :18085 · PG :15437 zod_dev_2660 · Docker CH :18128 clarity_dev_2660 (ledger docs/investigations/DEV-2660/qa-prep-ledger.json). Mac Herd refused. `cloud_touched=true` (read). `cloud_ch_writes=false`. No DSN share with the other live Path A twin.
**{floor}:** KEEP. Floor-off blows memory ~12.2GB vs ~234MB. −7 predicate stays.
**#18:** real fleet Docker CH required for Agent QA/Verify. Fleet not torn down.
**#25:** GREEN at 2026-09-28T14:23:53Z, box-side fleet RO measure (not this worker). AC-1 100% (9149/9149). AC-3 deduped 11975/11975, db_repos 9149/9149 (Δ0). AC-4 read_bytes −35% (34.96), read_rows −51% (51.15). m4 residue_triples=0. m5 null_first_repossessed_at=0 (FINAL and max(FRA); naive-join false nulls discarded). Historical repo_date 23% (1588/6770) is the closed comparison only.
**Ship:** Recoveries leg reads clarity.hotlist.first_repossessed_at (min()). public_hotlist FINAL (org, vin) match kept. No FINAL on clarity.hotlist. Product tip `255107c5b583e7f1b44c64bbc625281a0b8764aa`.
**Assignees:** Jira Dalton McCleery (unchanged). GitHub DaltonMcCleery.
<!-- /ticket-status -->
