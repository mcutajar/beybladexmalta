# project in symfony docker

launch with: `docker compose --env-file .env.docker -f compose.prod.yaml up -d`

dev mode: `docker compose --env-file .env.docker -f compose.override.yaml up -d --build`


# importing tournament results

The csv input must simply have the list of players, of course 1st place on top like:
```csv
l-anzjan
Southboy15
giglio
mezz
il-karm
sanya
myers
obelix
markinu
amanda
```

the command is executed as: 
```
php bin/console app:import-tournament \
  "Gamebreaker 20-06" \
  "2026-06-20" \
  "./docs/tournament-results/swiss-20260620.csv" \
  --challonge="https://worldbeyblade.challonge.com/co5nncw8" \
  -s "preseason" \
  -k "l-anzjan" \
  -vv
```
