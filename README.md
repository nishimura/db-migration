# db-migration
Simple DB migration tool


DB Migration tool for PostgreSQL.  
Version management by sh1 file hash, like git.

## Install

```bash
composer require nish/db-migration

mkdir db-migration

vendor/bin/db-migration.php -h
```

## Migration File

db-migration/001_Foo.php

```php
<?php
class Foo implements DbMigration
{
    public function up(PDO $pdo)
    {
        $pdo->exec('
create table foo(
  foo_id serial primary key,
  name text not null,
  body text
)
');
    }

    public function down(PDO $pdo)
    {
        $pdo->exec('drop table foo');
    }
}
```

run command

```bash
vendor/bin/db-migration.php

upgrade:
 3b1243                    001_Foo.php                  Foo                     

Migrate? [y/n]: y
up  : 3b1243736a19b730845aaf8521336e3bbce12122 ...ok
Success!!
```


## Scan file modifications automatic

change 001_Foo.php

```php
<?php

class Foo implements DbMigration
{
    public function up(PDO $pdo)
    {
        $pdo->exec('
create table foo(
  foo_id serial primary key,
  name text not null,
  body text,
  created_at timestamp not null default CURRENT_TIMESTAMP
)
');
    }

    public function down(PDO $pdo)
    {

        $pdo->exec('
drop table foo
');
    }
}
```

run command

```bash
vendor/bin/db-migration.php
Donwgrade:
 3b1243                    001_Foo.php                  Foo  2018-10-25 18:33:56
upgrade:
 9e4e3b                    001_Foo.php                  Foo                     

Migrate? [y/n]: y
down: 3b1243736a19b730845aaf8521336e3bbce12122 ...ok
up  : 9e4e3bb05f5e7de76f9971b1cc6afaa4b5ca6687 ...ok
Success!!
```

NOTE:
show file by git command

```
git commit

git cat-file -p 3b12437
```

## Migration by any files

This tool scans all php files in db-migration directory.

```
db-migration/
├── 001-Initialize
│   ├── Account.php
│   ├── Account.php~
│   └── Item.php
├── 002-Setup
│   ├── AccountSetup.php
│   └── FooSetup.php
└── 010-AddFeature1
    ├── AccountFeature1.php
    └── ItemFeature1.php
```

Scan order is file system base.  
Downgrade order is timestamp.
