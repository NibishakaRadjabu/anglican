# Anglican Church Management System

A PHP 8.1+ and MySQL application for parish membership, clergy, attendance, sacramental records, finance, events, announcements, and role-based user accounts.

## Run with XAMPP

1. Start Apache and MySQL from the XAMPP Control Panel.
2. Import `schema.sql` using phpMyAdmin or run this from the project directory:

   ```cmd
   C:\xampp\mysql\bin\mysql.exe -h 127.0.0.1 -P 3307 -u root < schema.sql
   ```

3. If your MySQL connection is not the XAMPP default, set `DB_HOST`, `DB_NAME`, `DB_USER`, and `DB_PASS` in the PHP environment. Defaults are `127.0.0.1`, `anglican_church`, `root`, and an empty password.
4. From the local machine, open `http://localhost/anglican/setup.php` and create the initial Bishop account. Use a unique password of at least 12 characters.
5. Sign in at `http://localhost/anglican/login.php`.

The first account is created only while the `users` table is empty. Do not expose the setup page publicly; after setup, keep the application behind HTTPS and restrict access to trusted users.

### Repair an unavailable `users` table

If MySQL reports error `1932` (`Table 'anglican_church.users' doesn't exist in engine`), the table is not accessible to InnoDB even if it appears in the table list. Back up the database before attempting recovery, then restore the affected table/database from a known-good backup or have the MySQL administrator repair the InnoDB table. Re-importing `schema.sql` does not repair an existing but inaccessible table because its table definitions use `IF NOT EXISTS`. For a genuinely new installation with no data to preserve, create a clean database and import `schema.sql` into it.

### Upgrade an existing database for parish management

1. Back up the `anglican_church` database.
2. Run this command once from the project directory:

   ```cmd
   C:\xampp\mysql\bin\mysql.exe -h 127.0.0.1 -P 3307 -u root anglican_church < migrations\branches.sql
   ```

   The migration expects the earlier `church_type` and `branch_name` columns on members and clergy. Existing named locations are converted into parishes; other legacy records are assigned to Headquarters. Existing parish staff accounts must be assigned to a parish in **User accounts** before they can sign in again.
3. Sign in as the Headquarters Bishop, create or update parishes from **Parishes**, then assign parish staff accounts to their parishes from **User accounts**.

Do not run the one-time migration more than once. Fresh installations should import `schema.sql` and do not need the migration. The application displays “Parish”; the existing database retains its `branches` table and `branch_id` columns internally.

For an existing database with branch management already installed, back it up and run `migrations\leadership_roles.sql` once before creating accounts with the new roles.

For parish creation requests, run `migrations\parish_requests.sql` once on an existing database. Fresh installations already include the request table in `schema.sql`.

## Roles

- **Headquarters Bishop:** Full access across all parishes, including user accounts and parish management. Headquarters reviews requests from parish Bishops before creating new parishes.
- **Parish Bishop:** Full access to records and activities in the assigned parish. Parish activity reports are stored centrally for headquarters review. A Parish Bishop can request a new parish, which remains uncreated until Headquarters approves it.
- **Archdeacon:** Full create, read, update, and delete access across all parishes, but cannot manage user accounts or parishes.
- **Parish Priest / Pastor, assistant clergy, parish council, secretary, treasurer, and ministry leaders:** Full create, read, update, and delete access within their assigned parish.
- **Member:** Events and published announcements, plus only their linked profile, attendance, and sacramental records.

A Headquarters Bishop manages the parish register and assigns staff accounts to parishes from **User accounts**. Parish Bishops manage records and submit activity reports for their assigned parish. Archdeacons can view and manage records across all parishes. Parish activity is stored in the shared central database, so headquarters can review it without a separate sync step. For a Member account, link an existing member record so the personal-data restrictions can be applied.

## Parish activity planning

Fresh installations include the activity register and editable annual/weekly programme templates. For an existing database, first apply the leadership-role migration if it has not already been applied, then back up the database and run `migrations\parish_activities.sql` once.

Activity creators are the responsible leaders and can edit or delete their own activities. Parish supervisors and Parish Bishops can oversee activities within their parish; Archdeacons and the Headquarters Bishop can oversee activities across parishes. Activity reports are shared immediately with authorized parish users and headquarters; the system does not impose a separate approval gate. The sample 2027 programme is illustrative: confirm local dates, titles, approvals, budget controls and reporting requirements with the relevant diocese.

## Languages

Use the language selector on the sign-in/setup screen or in the navigation panel to switch between English, French, Kiswahili, and Kinyarwanda. The selection is kept for the current browser session.

## Notes

- SMS and email delivery are not connected; announcements and publish dates are stored for church communications.
- Financial amounts are stored as decimal values without a configured currency symbol.
- Database backups, mail/SMS provider setup, and deployment-specific HTTPS configuration are operational responsibilities.
