# Community Connect website

WordPress code for community-connect.cc.

| Folder | What it is |
|---|---|
| `cc-fleet/` | The CC Fleet plugin: bikes, riders, loans, calendar, request form, emails, bike pages. |
| `community-connect/` | The block theme (Mobile 2027 design). Content and images are edited in Appearance → Editor. |
| `tools/landing/` | Source of the home-page fragments (`build.py` writes into `cc-fleet/`). |
| `tools/theme/` | Generator for the theme's templates and patterns. |

## Releasing an update

1. Raise the version: `Version:` in `cc-fleet/cc-fleet.php` (and `CC_FLEET_VERSION`) and/or `Version:` in `community-connect/style.css`.
2. Commit, then push a tag: `git tag v2026.11.22 && git push --tags`.
3. The **Release** workflow builds `cc-fleet-<version>.zip` and `community-connect-<version>.zip` and publishes a release.
4. The site checks every 12 hours (or immediately via Fleet Manager → Updates → *Save and check now*) and shows the update under Plugins / Themes.

Never commit rider data, exports or passwords. Test data uses `example.org` addresses and `082 555 …` numbers.
