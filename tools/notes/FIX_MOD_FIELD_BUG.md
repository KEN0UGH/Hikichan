# Fixed: is_mod Bug - Guests Seeing Mod Settings

## Problem
When posting from the mod page in Vichan, guests would see the mod settings of a thread instead of the regular thread page. Additionally, when viewing saved thread HTML files directly, mod options were visible even to guests.

## Root Cause - Part 1: Missing Database Field
In the unified boards table migration (commit dde312d), the `mod` database field was **never added** to:
1. The `posts` table schema (install.sql)
2. The INSERT statement that stores posts (inc/functions.php)

Without this field, `$post['mod']` was set in memory when a mod posted but **never stored** in the database.

## Root Cause - Part 2: HTML Files Built with Mod Context
Even more critically, `buildThread()` and `buildThread50()` were building and saving HTML files **with the `$mod` parameter value intact**. This meant:
- When a mod posted and triggered a rebuild, the thread HTML was built with mod controls visible
- This HTML file was then saved and served to ALL users (including guests)
- Guests would see the mod options that were baked into the saved HTML file

## Solution Implemented

### Changes Made:

#### 1. `install.sql` - Added `mod` column to schema
```sql
`mod` bool NOT NULL DEFAULT 0,
```
Stores whether a post was made by a moderator.

#### 2. `inc/functions.php` - Updated INSERT statement
Added `:mod` to the field list and VALUES clause to persist the mod flag.

#### 3. `inc/functions.php` - Critical: Fixed buildThread() function
**KEY FIX:** Changed `buildThread()` to:
- Always build the PUBLIC HTML file with `$mod = false` (no mod controls)
- Save this public version to disk
- Only generate a mod-specific view when a mod explicitly requests a return value
- Never expose mod controls in saved HTML files

#### 4. `inc/functions.php` - Critical: Fixed buildThread50() function  
Applied the same fix to the noko50 thread builder.

## Migration for Existing Databases

If you have an existing Hikichan installation, you'll need to add the missing column:

```sql
ALTER TABLE `posts` ADD COLUMN `mod` bool NOT NULL DEFAULT 0;
```

Run this in PHPMyAdmin or your database client.

## Additional Actions Required

After applying the fix, you should **rebuild all thread files** to ensure mod controls are removed:

```php
// Use your rebuild tools or run:
// tools/rebuild.php or tools/rebuild2.php
```

This will regenerate all thread HTML files without mod controls visible.

## Verification

After applying this fix and rebuilding:
1. ✅ Guests viewing thread URLs directly see NO mod options
2. ✅ Posts made by mods have `mod=1` stored in database
3. ✅ Posts made by regular users have `mod=0` stored
4. ✅ HTML files saved to disk are always public view (no mod controls)
5. ✅ Mods see appropriate controls only in mod panel with proper context
6. ✅ Thread rendering correctly uses the viewer's mod status, not the post's stored flag

## Files Changed
- `install.sql` - Schema fix  
- `inc/functions.php` - Database storage AND HTML file generation fixes
- (This fix document)

## Related
- Commit: dde312d (unified boards into one table)
- Issue: Unified boards table migration incompleteness + HTML generation with mod context
