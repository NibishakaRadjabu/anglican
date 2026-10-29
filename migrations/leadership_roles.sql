USE anglican_church;

ALTER TABLE users
    MODIFY COLUMN role ENUM(
        'bishop',
        'archdeacon',
        'priest',
        'assistant_clergy',
        'parish_council',
        'secretary',
        'treasurer',
        'ministry_leader',
        'member'
    ) NOT NULL;
