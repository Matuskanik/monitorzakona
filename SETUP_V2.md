# Setup Instructions for v2 - $0 Tech Stack

## ✅ What Has Been Done

I've successfully migrated your app to a **$0 tech stack** based on the X/Twitter post recommendations:

### Changes Made:

1. **✅ JSON Data Generator** (`bin/generate-json-data.php`)
   - Exports all laws from SQLite to `data/laws/*.json` files
   - Creates `data/index.json` with law list
   - No database needed for frontend!

2. **✅ GitHub Actions Workflow** (`.github/workflows/generate-data.yml`)
   - Runs every 6 hours automatically
   - Processes new laws using your existing PHP code
   - Generates JSON files
   - Commits and pushes them to the repo
   - Cloudflare Pages auto-deploys on commit

3. **✅ Static Frontend** (`public/index.html`, `public/law.html`)
   - Pure HTML/JavaScript (no PHP server needed)
   - Reads from JSON files instead of database
   - Works perfectly on Cloudflare Pages

4. **✅ Cloudflare Pages Config**
   - `public/_redirects` for URL routing
   - `wrangler.toml` for Cloudflare configuration

5. **✅ Updated Documentation**
   - `DEPLOYMENT.md` with full setup guide
   - Updated `README.md` with new deployment option

## 🔧 What You Need to Do

### Step 1: Set Up GitHub Secrets

1. Go to your GitHub repository: `https://github.com/Matuskanik/monitorzakona`
2. Navigate to **Settings** → **Secrets and variables** → **Actions**
3. Click **New repository secret**
4. Add:
   - **Name**: `OPENAI_API_KEY`
   - **Value**: Your OpenAI API key
5. Click **Add secret**

### Step 2: Enable GitHub Actions Workflow Permissions

1. Go to **Settings** → **Actions** → **General**
2. Under **Workflow permissions**, select:
   - ✅ **Read and write permissions**
   - ✅ **Allow GitHub Actions to create and approve pull requests**
3. Click **Save**

### Step 3: Set Up Cloudflare Pages

1. Go to [Cloudflare Dashboard](https://dash.cloudflare.com/)
2. Click **Pages** → **Create a project**
3. Click **Connect to Git**
4. Select your GitHub account and repository (`monitorzakona`)
5. Configure:
   - **Project name**: `sentinel-law-monitor` (or your choice)
   - **Production branch**: `v2`
   - **Framework preset**: `None` (or `Static`)
   - **Build command**: (leave empty)
   - **Build output directory**: `public`
   - **Root directory**: `/` (leave as root)
6. Click **Save and Deploy**

### Step 4: Test the Workflow

1. Go to your GitHub repository → **Actions** tab
2. Find **Generate Law Data** workflow
3. Click **Run workflow** → **Run workflow** (manual trigger)
4. Wait for it to complete (takes ~5-10 minutes)
5. Check that `data/index.json` and `data/laws/*.json` files were created/updated

### Step 5: Verify Cloudflare Pages Deployment

1. After the workflow completes, Cloudflare Pages should auto-deploy
2. Check your Cloudflare Pages dashboard for deployment status
3. Visit your Cloudflare Pages URL (e.g., `https://your-project.pages.dev`)
4. You should see the law list page!

## 📋 Cost Breakdown

- **GitHub Actions**: ✅ Free (2,000 min/month for private, unlimited for public)
- **Cloudflare Pages**: ✅ Free (unlimited requests, 500 builds/month)
- **OpenAI API**: 💰 Pay-per-use (only cost)
- **Total**: ~$0 (only OpenAI API costs)

## 🎯 Architecture Overview

```
┌─────────────────┐
│ GitHub Actions  │  (Runs every 6 hours)
│  - cron.php     │  → Processes laws
│  - generate-    │  → Creates JSON files
│    json-data.php│  → Commits to repo
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│  GitHub Repo    │
│  - data/laws/   │  ← JSON files stored here
│  - data/index.json│
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│Cloudflare Pages │  (Auto-deploys on commit)
│  - index.html   │  → Static frontend
│  - law.html     │  → Reads JSON files
└─────────────────┘
```

## 🚀 Next Steps After Setup

1. **Monitor first automated run** (in 6 hours)
2. **Set up custom domain** (optional, in Cloudflare Pages settings)
3. **Configure analytics** (optional, add PostHog/Microsoft Clarity/Google Analytics)
4. **Adjust workflow schedule** (if needed, edit `.github/workflows/generate-data.yml`)

## ❓ Troubleshooting

### Workflow fails to commit
- Check that workflow has write permissions (Step 2 above)
- Verify `GITHUB_TOKEN` has proper permissions

### Cloudflare Pages not deploying
- Check build logs in Cloudflare dashboard
- Verify build output directory is `public`
- Ensure `_redirects` file exists in `public/`

### No data showing
- Check that `data/index.json` exists in the repo
- Verify JSON files are valid (check GitHub Actions logs)
- Check browser console for errors

## 📝 Notes

- The old PHP frontend (`index.php`, `law.php`) still works if you want to use it
- The new static version (`index.html`, `law.html`) is recommended for Cloudflare Pages
- SQLite database is still used during processing (in GitHub Actions), but not needed for the frontend
- All processing happens in GitHub Actions, frontend is completely static

## 🎉 Benefits

✅ **Zero hosting costs**  
✅ **No database to maintain**  
✅ **Fast CDN-cached static site**  
✅ **Automatic deployments**  
✅ **Scalable** (Cloudflare handles traffic)  
✅ **Simple architecture** (fewer moving parts)

---

**Ready to continue?** Once you've completed the setup steps above, let me know and we can test everything together!
