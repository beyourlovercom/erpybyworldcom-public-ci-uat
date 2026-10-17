# YBY ERP Windows Local Runtime

Canonical local URL: `https://localerp.ybyworld.com`

- Code: `D:\ai\localerp.ybyworld.com`
- Git truth: `beyourlovercom/erpybyworldcom`
- Local DB: MySQL 8.4, service `YBYERPMySQL`, port `13306`, database `yby_erp_local`
- PHP: Windows PHP 8.3 with repo-local ini under `.runtime/php83/php.ini`
- App HTTP origin: `127.0.0.1:18180`
- HTTPS/SNI: Local router nginx proxies `localerp.ybyworld.com` to the app origin
- Auto-start: Windows Scheduled Task `YBY ERP Local Runtime`
- Local Owner: `owner@ybyworld.local`, local-only automatic bypass enabled

The local environment is independent from `erp.beyourlover.com`; no BYL Production database is used here.
Secrets, runtime logs, certificates, and `.env` are not committed.
