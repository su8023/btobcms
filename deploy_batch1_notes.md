# Deploy Batch 1 Notes (Posts 3, 4, 6)
- Date: 2026-11-07 18:28:03 UTC
- Backup: storage/app/private/posts_backup_before_batch1.json
- Actions:
  - Post ID 3 status -> published
  - Post ID 4 status -> published
  - Post ID 6 inserted (Ningbo-Yuyao plastic injection molding) -> published
  - Clear cache: optimize:clear + Cache::flush()
- Verification: Post::where('status', 'published')->count() == 3
- Brand check: 0 banned brand words.
