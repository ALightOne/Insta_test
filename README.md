# noinstagram

I got rid of my IG account some time ago, but I'd still like to see [photos from my wife](https://instagram.com/juiskie/) and [our dog](https://instagram.com/scooterpablovance/). I was just visiting the IG website for each public user I wanted to view, but that was way too much work. So I [built this](https://datvance.github.io/noinstagram/) one Sunday.

## Want your own noinstagram page?

- fork this repo
- set up [github pages](https://pages.github.com) for the forked repo and point it at /docs
- edit accounts.json with your preferred IG accounts and/or hashtags
- add a repository secret `INSTAGRAM_SESSIONID` (Settings → Secrets and variables → Actions) with the value of the
  `sessionid` cookie from a browser logged in to Instagram (use a secondary account). Instagram usually refuses
  anonymous requests coming from GitHub's servers, so without it the build will most likely fail.
- that's it. A GitHub Action should run every 6 hours updating your page. (You can change this in .github/workflows/build.yml)

Only public user accounts are supported; hashtags need a logged-in session on Instagram's side and are skipped.
If no posts can be fetched at all, `build.php` exits with an error, the workflow run fails and the page is left
unchanged. Accounts that fail individually are shown as warnings in the run summary. The page shows when it was
last generated (time zone set by `TIMEZONE` in the workflow).

You can also build using the noish.sh script. Just edit that script and set your GitHub username, and set
an environment variable with your GitHub access token.  

### also...

- no dependencies: only PHP with the curl extension (preinstalled on GitHub's ubuntu runners)
- style and features are pretty basic, but hey, pull requests welcome
- there are some variables in build.php you might want to change
