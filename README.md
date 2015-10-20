# fluxbb-necro
## Thread necromancy game for FluxBB

### Copyright (c) 2015 Mark "zini" Mäkinen
License: MIT (check LICENCE.txt)

## How to install:
1. Run "schema.sql" with SQLite3 to create tables
2. Set constants
  1. Set NECRO_PATH to the absolute path that points to the script folder (with trailing slash)
  2. Set NECRO_FORUM_URL to the FluxBB forum url (with trailing slash)
  3. Set NECRO_THREAD_ID to the game thread id
3. Create update.txt ("touch update.txt")
4. Run this file in CLI to update data
  1. Create cronjob (if you want to) to update data ("php5 necro.php"))
