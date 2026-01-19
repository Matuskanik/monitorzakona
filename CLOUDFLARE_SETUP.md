# Cloudflare Pages Setup - Quick Reference

## Steps to Deploy

1. **Go to**: https://dash.cloudflare.com/
2. **Click**: Pages → Create a project
3. **Click**: Connect to Git
4. **Select**: Your GitHub account → `monitorzakona` repository
5. **Configure**:
   - Project name: `sentinel-law-monitor`
   - Production branch: `v2` ⚠️ **IMPORTANT**
   - Framework preset: `None` or `Static`
   - Build command: (leave empty)
   - Build output directory: `public` ⚠️ **IMPORTANT**
   - Root directory: `/` (leave as root)
6. **Click**: Save and Deploy

## After Deployment

- Your site will be live at: `https://sentinel-law-monitor.pages.dev`
- Cloudflare Pages will auto-deploy on every push to `v2` branch
- No manual deployments needed!

## Custom Domain (Optional)

1. In Cloudflare Pages dashboard, click your project
2. Go to **Custom domains**
3. Add your domain
4. Follow DNS setup instructions

## Verification

After deployment, visit your Pages URL and you should see:
- ✅ Law list page (`index.html`)
- ✅ Law detail pages work (`law.html?master_id=10606`)
- ✅ Search functionality works
- ✅ All data loads from JSON files

## Troubleshooting

- **404 errors**: Check that build output directory is set to `public`
- **No data showing**: Verify `data/index.json` exists in the repository
- **Build fails**: Check build logs in Cloudflare dashboard
