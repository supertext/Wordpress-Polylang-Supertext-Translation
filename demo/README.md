# WordPress demo site

Docker image of a WordPress + Polylang site with this plugin, used for the demo on Railway.
Variables, setup steps and limits: see the main [README → Demo site on Railway](../README.md#demo-site-on-railway-docker).

```sh
docker build -f demo/Dockerfile -t wordpress-supertext-demo .
docker run -p 8080:8080 -e DATABASE_URL=mysql://root:secret@host:3306/wordpress \
  -e DEMO_ADMIN_EMAIL=admin@example.com -e DEMO_ADMIN_PASSWORD=change-me \
  -e DEMO_SITE_URL=http://localhost:8080 wordpress-supertext-demo
```
