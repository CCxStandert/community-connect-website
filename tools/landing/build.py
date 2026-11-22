"""Builds the landing page twice from template.html:
   1. cc-site/index.html: a standalone preview (artifact), fleet snapshot inline.
   2. The CC Fleet plugin's WordPress page template: templates/landing.php + assets/landing.css + assets/landing.js + assets/landing/*.
"""
import json, re, os, csv, markdown
SITE = '/home/claude/cc-site/'
PLUG = '/home/claude/cc-fleet/'
t = open(SITE + 'template.html').read()
logo, icon = open(SITE + 'logo.inner').read(), open(SITE + 'icon.inner').read()
agreement = markdown.markdown(open(SITE + 'agreement.md').read())
# Agreement as an accordion: the title is the section heading on the page, each "## part" a <details>.
agreement = re.sub(r'<h1>.*?</h1>\s*', '', agreement, count=1, flags=re.S)
parts = re.split(r'<h2>(.*?)</h2>', agreement)
head, rest = parts[0], parts[1:]
head = head.replace('<p>', '<p class="intro">', 2).replace('<p class="intro"><em>', '<p class="mono"><em>', 1)
acc = ''
for i in range(0, len(rest), 2):
    title, body = rest[i], rest[i + 1].strip()
    slug = 'ag-' + re.sub(r'[^a-z0-9]+', '-', re.sub(r'<[^>]+>', '', title).lower()).strip('-')
    acc += f'<details id="{slug}"><summary>{title}</summary>\n{body}\n</details>\n'
agreement = head + acc
css = re.search(r'<style>(.*?)</style>', t, re.S).group(1)
js = re.search(r'<script>\n(.*?)</script>\n</body>', t, re.S).group(1)
body = re.search(r'<body class="cc-landing">\n(.*?)\n<script>', t, re.S).group(1)

bikes = json.load(open(SITE + 'bikes.json'))
events = []
for r in csv.DictReader(open('/home/claude/events2027/cc-events-2027.csv')):
    events.append({'name': r['name'], 'start': r['start_date'], 'end': r['end_date'] or r['start_date'], 'where': r['location'], 'type': r['type'], 'link': r['link']})

# 1. standalone preview
cfg = {'bikes': bikes, 'events': events, 'horizon': '2027-12-31', 'icon': icon, 'api': None, 'live': False, 'img': 'img'}
static_any = '<p style="padding:22px;margin:0">On community-connect.cc the request form sits right here. <a href="#bikes">Pick a bike above</a> for now.</p>'
page = t.replace('__IMG__', 'img').replace('__LOGO__', logo).replace('__ICON__', icon).replace('__AGREEMENT__', agreement).replace('__ANY__', static_any)
page = page.replace('<script>\n{', '<script>window.CCLanding = ' + json.dumps(cfg, ensure_ascii=False) + ';</script>\n<script>\n{', 1)
open(SITE + 'index.html', 'w').write(page)

# 2. WordPress
os.makedirs(PLUG + 'templates', exist_ok=True)
os.makedirs(PLUG + 'assets/landing', exist_ok=True)
open(PLUG + 'assets/landing.css', 'w').write('/* Built from cc-site/template.html by build.py. Edit there. */' + css)
open(PLUG + 'assets/landing.js', 'w').write('/* Built from cc-site/template.html by build.py. Edit there. */\n' + js)
open(PLUG + 'assets/landing/logo.svg.inner', 'w').write(logo)
open(PLUG + 'assets/landing/icon.svg.inner', 'w').write(icon)
open(PLUG + 'assets/landing/agreement.html', 'w').write(agreement)
import shutil
shutil.rmtree(PLUG + 'assets/landing/img', ignore_errors=True)
shutil.copytree(SITE + 'img', PLUG + 'assets/landing/img')
php_body = (body.replace('__IMG__', "<?php echo esc_url( CC_FLEET_URL . 'assets/landing/img' ); ?>").replace('__LOGO__', "<?php echo CC_Fleet_Landing::svg( 'logo' ); // phpcs:ignore ?>")
    .replace('__ICON__', "<?php echo CC_Fleet_Landing::svg( 'icon' ); // phpcs:ignore ?>")
    .replace('__AGREEMENT__', "<?php echo CC_Fleet_Landing::agreement(); // phpcs:ignore ?>")
    .replace('__ANY__', "<?php echo do_shortcode( '[cc_bike_request]' ); // phpcs:ignore ?>")
    .replace('<a href="#top" aria-label', '<a href="<?php echo esc_url( home_url( \'/\' ) ); ?>#top" aria-label'))
php = """<?php
/**
 * CC Landing: the home page. Built from cc-site/template.html by build.py.
 * Full-page template: the theme's header and styles are left out so the page looks exactly like the design.
 */
defined( 'ABSPATH' ) || exit;
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="Borrow a premium gravel or road bike from the Community Connect × Standert fleet in Cape Town. Free for anyone. Ride it, look after it, pass it on.">
<?php wp_head(); ?>
</head>
<body <?php body_class( 'cc-landing' ); ?>>
<?php wp_body_open(); ?>
""" + php_body + """
<?php wp_footer(); ?>
</body>
</html>
"""
os.makedirs(PLUG + 'assets/landing/parts', exist_ok=True)
for name in ['fleet', 'loop', 'events', 'stats', 'game']:
    frag = re.search(r'<!--cc:' + name + r'-->(.*?)<!--/cc:' + name + r'-->', t, re.S).group(1).strip()
    open(PLUG + 'assets/landing/parts/' + name + '.html', 'w').write(frag + '\n')
open(PLUG + 'templates/landing.php', 'w').write(php.replace('<meta name="viewport" content="width=device-width, initial-scale=1">\n', ''))
print('built', len(page), len(css), len(js))
