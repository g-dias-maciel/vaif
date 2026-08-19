Type: grilling
Status: open
Blocked by: none

## Question

What URL and routing structure should the blog and artist pages use, given this is a no-framework PHP site deployed on Coolify?

The site currently serves `index.php` and `calculadora.php` directly with no router. We need clean, SEO-friendly URLs for:
- Blog posts: individual post pages + a listing/index page
- Artist pages: one page per artist at a stable URL

Options to evaluate:
1. **Directory-based with Apache/Nginx rewrites** — `/blog/post-slug`, `/artists/artist-slug`. Cleanest for SEO, needs server config or .htaccess.
2. **Front-controller with query params** — `/blog.php?slug=post-slug`. No server config needed, less pretty.
3. **PHP file per artist in a directory** — `/artists/joao/index.php`. Each artist gets a directory with their config, no router at all.

Considerations: Coolify deployment constraints, SEO impact (Google + AI search), maintainability at 5-20 artist scale, how n8n page creation fits into the routing choice.
