"""Generates the Community Connect theme's templates, parts and patterns (block markup)."""
import json, re, markdown, html
T = '/home/claude/cc-theme/community-connect/'
IMG = lambda f: f"<?php echo esc_url( get_theme_file_uri( 'assets/images/{f}' ) ); ?>"

def j(d): return json.dumps(d, separators=(',', ':'), ensure_ascii=False)
def P(text, cls='', extra=None, tag='p'):
    a = {}
    if cls: a['className'] = cls
    if extra: a.update(extra)
    att = (' ' + j(a)) if a else ''
    c = f' class="{cls}"' if cls else ''
    return f'<!-- wp:paragraph{att} -->\n<p{c}>{text}</p>\n<!-- /wp:paragraph -->'
def H(text, level=2, cls='', anchor=''):
    a = {}
    if level != 2: a['level'] = level
    if cls: a['className'] = cls
    if anchor: a['anchor'] = anchor
    att = (' ' + j(a)) if a else ''
    classes = ' '.join(x for x in ['wp-block-heading', cls] if x)
    idattr = f' id="{anchor}"' if anchor else ''
    return f'<!-- wp:heading{att} -->\n<h{level} class="{classes}"{idattr}>{text}</h{level}>\n<!-- /wp:heading -->'
def G(inner, cls='', anchor='', tag='div', align='', gap0=True, extra=None):
    a = {}
    if tag != 'div': a['tagName'] = tag
    if cls: a['className'] = cls
    if anchor: a['anchor'] = anchor
    if align: a['align'] = align
    if gap0: a['style'] = {'spacing': {'blockGap': '0'}}
    a['layout'] = {'type': 'default'}
    if extra: a.update(extra)
    classes = ' '.join(x for x in ['wp-block-group', f'align{align}' if align else '', cls] if x)
    idattr = f' id="{anchor}"' if anchor else ''
    return f'<!-- wp:group {j(a)} -->\n<{tag} class="{classes}"{idattr}>{inner}</{tag}>\n<!-- /wp:group -->'
def SC(code): return f'<!-- wp:shortcode -->\n{code}\n<!-- /wp:shortcode -->'
def DET(summary, inner, anchor=''):
    a = {'anchor': anchor} if anchor else {}
    att = (' ' + j(a)) if a else ''
    idattr = f' id="{anchor}"' if anchor else ''
    return f'<!-- wp:details{att} -->\n<details class="wp-block-details"{idattr}><summary>{summary}</summary>{inner}</details>\n<!-- /wp:details -->'
def UL(items):
    li = ''.join(f'<!-- wp:list-item -->\n<li>{x}</li>\n<!-- /wp:list-item -->' for x in items)
    return f'<!-- wp:list -->\n<ul class="wp-block-list">{li}</ul>\n<!-- /wp:list -->'
def IMGB(src, alt, cls=''):
    a = {'sizeSlug': 'large'}
    if cls: a['className'] = cls
    c = ' '.join(x for x in ['wp-block-image', 'size-large', cls] if x)
    return f'<!-- wp:image {j(a)} -->\n<figure class="{c}"><img src="{src}" alt="{alt}"/></figure>\n<!-- /wp:image -->'
def COVER(img, inner, cls='', href='', pos='50% 50%'):
    """A photo panel: a group with a background image (change it in the block's Background panel)."""
    a = {'className': 'cc-cover ' + cls, 'style': {'background': {'backgroundImage': {'url': '__URL__', 'source': 'file', 'title': ''}, 'backgroundSize': 'cover', 'backgroundPosition': pos}, 'spacing': {'blockGap': '0'}}, 'layout': {'type': 'default'}}
    att = j(a).replace('"__URL__"', '"' + img + '"')
    return f'<!-- wp:group {att} -->\n<div class="wp-block-group cc-cover {cls}">{inner}</div>\n<!-- /wp:group -->'
def BTN(text, href, style=''):
    a = {'className': f'is-style-{style}'} if style else {}
    att = (' ' + j(a)) if a else ''
    c = ' '.join(x for x in ['wp-block-button', f'is-style-{style}' if style else ''] if x)
    return f'<!-- wp:buttons -->\n<div class="wp-block-buttons"><!-- wp:button{att} -->\n<div class="{c}"><a class="wp-block-button__link wp-element-button" href="{href}">{text}</a></div>\n<!-- /wp:button --></div>\n<!-- /wp:buttons -->'

def pattern(slug, title, body, desc=''):
    head = f"<?php\n/**\n * Title: {title}\n * Slug: community-connect/{slug}\n * Categories: community-connect\n * Description: {desc}\n */\n?>\n"
    open(T + f'patterns/{slug}.php', 'w').write(head + body + '\n')

# ---------- patterns ----------
slides = [
    ('hero-ghc.webp', 'Free to borrow', 'We share bikes.', 'Here is how you ride one.', '10 standerts, 0 fees x', '#fleet'),
    ('repaint.webp', 'New project', 'Gravel Hearts Club', 'Cute and Dirty', '2 bikes, repainted x', '#fleet'),
    ('desolation.webp', 'Events 2027', 'Race it on a fleet bike', 'Gravel races, dates out.', 'racing terms apply x', '#events'),
]
covers = ''
for img, tag, title, l2, hand, href in slides:
    inner = P(tag, 'is-style-pill-tag') + H(f'<a href="{href}">{title}</a>', 2, 'cc-hero-title') + P(l2, 'cc-l2') + P(hand, 'is-style-hand-note')
    covers += COVER(IMG(img), inner, 'cc-slide')
pattern('hero', 'Home: photo slides', G(covers, 'slides', anchor='slides', align='full'), 'Swipeable photo slides. Replace the images and words; add or remove slides.')

pattern('fleet', 'Home: explore the fleet', G(
    G(H('Explore the fleet', 2, 'h', 'fleet') + P('10 Standerts · free to borrow · book up to 31 Dec 2027', 'sub'), 'in max') +
    SC('[cc_home_fleet]'), 'sect', align='full'), 'Heading and the live size finder, filters, date check and bike grid.')

pattern('fleet-banner', 'Home: fleet news banner', G(G(
    P('Fleet 2027', 'is-style-mono-label') + H('New Kieswerks in 52 and 56 are on the way.', 2, 'cc-banner-title') + P('bye bye Erdgeschoss 54 + 56 x', 'is-style-hand-note'),
    'max'), 'banner', align='full', extra={'backgroundColor': 'ochre', 'textColor': 'white'}).replace('class="wp-block-group alignfull banner"', 'class="wp-block-group alignfull banner has-white-color has-ochre-background-color has-text-color has-background"'),
    'Ochre banner for fleet news.')

steps = [('Pick', 'Pick a bike', 'Open a bike, tap your first and last day and send the request. Racing? Pick the event.'),
         ('Yes', 'We say yes', 'We check who has the bike before you. You get a yes with the handoff, or other dates or bikes.'),
         ('Handoff', 'Meet the last rider', 'Collect from the rider before you, or from the crew. Check the bike together and flag anything off.'),
         ('Ride', 'Ride', 'It’s yours for those dates. Something feels off? Message us early.'),
         ('Return', 'Bring it back clean', 'Same condition you got it in. A dirty bike means you cover a wash &amp; lube.'),
         ('Pass on', 'Pass it on', 'Hand it over, share what the bike let you do, and come back for another one.')]
step_blocks = ''.join(G(P(f'0{i+1} / {a}', 'mono') + H(b, 3) + P(c), '') for i, (a, b, c) in enumerate(steps))
pattern('how-it-works', 'Home: how it works', G(
    COVER(IMG('getaways.webp'), H('How it works', 2, 'cc-photo-title', 'how') + P('Six steps, mostly on your phone.', 'cc-l2') + P('one free day between loans x', 'is-style-hand-note'), 'cc-photo') +
    G(SC('[cc_how_loop]'), 'in max') +
    G(step_blocks, 'row max', anchor='how-steps'),
    '', align='full'), 'Photo, the loop animation and the six step cards. Edit the cards: the animation uses their headings.')

terms = G(
    G(P('Racing terms', 'is-style-pill-tag cc-tag-ochre') + P('<strong>Racing is harder on a bike than riding.</strong> For an event you tick one extra line: damage while racing, crashes included, is yours to cover. Repairs at Woodstock Cycleworks, invoice shared, no markup.'), 'cream') +
    G(P('Raffle bikes', 'is-style-pill-tag') + P('<strong>Win a bike, an entry, sometimes kit and nutrition.</strong> Some bikes stay held for organisers’ raffles until the winner is drawn. They show as raffle bikes and go back into the pool if nobody claims them.'), 'pinkb'),
    'terms max')
pattern('events', 'Home: events', G(
    G(H('Events', 2, 'h', 'events') + P('Gravel races with dates out. Pick one on the request form and we plan the bike around it.', 'sub'), 'in max') +
    SC('[cc_events_list]') + terms + P('Dates as published by the organisers. Always confirm on the event’s own page before you enter.', 'src max'),
    'sect', align='full'), 'The live events list (Fleet Manager → Events) with the racing and raffle notes.')

pattern('numbers', 'Home: numbers', G(SC('[cc_numbers]'), '', align='full'), 'Loans this year, riders and events, counted live.')

pattern('deal', 'Home: the deal', G(
    G(H('The deal, between friends', 2, 'h', 'deal') + P('We keep the bikes in shape. You bring them back the way you got them. Who pays?', 'sub'), 'in max') +
    G(SC('[cc_who_pays]') +
      DET('We cover', UL(['Regular servicing with Woodstock Cycleworks', 'Normal wear: chain, tyres, brake pads', 'A puncture on a long day out', 'Finding you a bike as often as we can'])) +
      DET('You cover', UL(['Breaks from reckless riding or the wrong kind of use', 'Damage while racing, crashes included', 'A wash &amp; lube if it comes back dirty', 'Telling us early, and flagging damage at the handover'])),
      'deal max'),
    'sect', align='full'), 'The who-pays game and what we cover / you cover.')

pattern('ask', 'Home: ask for any bike', G(
    G(H('Any bike will do?', 2, 'h', 'ask') + P('Ask for any bike in your size. We pick one that’s free on your dates.', 'sub'), 'in max') +
    G(SC('[cc_bike_request]'), 'ask max'), 'sect', align='full'), 'The request form for any bike in a size.')

draw = G(
    G(G(IMGB(IMG('bear.webp'), 'Hand-drawn bear waving a flag, with the Standert lettering'), 'art', extra={'backgroundColor': 'pink'}).replace('class="wp-block-group art"', 'class="wp-block-group art has-pink-background-color has-background"') + P('<strong>Gravel Hearts Club</strong>'), 'cc-fig') +
    G(G(IMGB(IMG('eatmydust.webp'), 'Hand-lettered Eat my Dust with a poodle'), 'art', extra={'backgroundColor': 'sand'}).replace('class="wp-block-group art"', 'class="wp-block-group art has-sand-background-color has-background"') + P('<strong>Eat my Dust</strong>'), 'cc-fig'),
    'drawings max')
pattern('drawings', 'Home: drawings', G(draw, '', align='full'), 'Two drawings by Kooooos with captions.')

# agreement from the markdown, as editable blocks
md = open('/home/claude/cc-site/agreement.md').read()
md = re.sub(r'^# .*\n', '', md)
parts = re.split(r'^## (.*)$', md, flags=re.M)
head, rest = parts[0].strip(), parts[1:]
def md_blocks(text):
    out = ''
    h = markdown.markdown(text)
    for m in re.finditer(r'<(p|ul)>(.*?)</\1>', h, re.S):
        if m.group(1) == 'p':
            out += P(m.group(2).strip())
        else:
            out += UL([x.strip() for x in re.findall(r'<li>(.*?)</li>', m.group(2), re.S)])
    return out
hp = re.findall(r'<p>(.*?)</p>', markdown.markdown(head), re.S)
ag = P(re.sub(r'</?em>', '', hp[0]), 'is-style-mono-label') + P(hp[1], 'is-style-story intro')
for i in range(0, len(rest), 2):
    title = rest[i].strip()
    slug = 'ag-' + re.sub(r'[^a-z0-9]+', '-', title.lower()).strip('-')
    ag += DET(title, md_blocks(rest[i + 1]), slug)
pattern('agreement', 'Home: the agreement', G(
    G(H('The agreement', 2, 'h', 'agreement') + P('Not legal small print. How friends share something they love.', 'sub'), 'in max') +
    G(ag, 'agree max'), 'sect', align='full'), 'The borrowing agreement as fold-out sections. Riders accept this text with every request.')

pattern('after-ride', 'Home: after the ride', G(COVER(IMG('after-ride.webp'),
    H('After the ride', 2, 'cc-photo-title') + P('Tell us what the bike let you do.', 'cc-l2') + P('<a href="https://www.instagram.com/cc_x_standert/">@cc_x_standert</a>', 'is-style-hand-note'), 'cc-photo', pos='62% 60%'), 'cc-after', align='full'),
    'Photo link to Instagram.')

# ---------- parts ----------
header = G(G(
    '<!-- wp:navigation {"overlayMenu":"mobile","className":"cc-nav","layout":{"type":"flex","justifyContent":"right"}} -->\n'
    '<!-- wp:navigation-link {"label":"Fleet","url":"/#fleet","kind":"custom"} /-->\n'
    '<!-- wp:navigation-link {"label":"How it works","url":"/#how","kind":"custom"} /-->\n'
    '<!-- wp:navigation-link {"label":"Rides","url":"/#rides","kind":"custom"} /-->\n'
    '<!-- wp:navigation-link {"label":"Events","url":"/#events","kind":"custom"} /-->\n'
    '<!-- wp:navigation-link {"label":"The deal","url":"/#deal","kind":"custom"} /-->\n'
    '<!-- wp:navigation-link {"label":"Agreement","url":"/#agreement","kind":"custom"} /-->\n'
    '<!-- /wp:navigation -->' +
    G('<!-- wp:site-logo {"width":28,"shouldSyncIcon":false,"className":"cc-logo"} /-->' + '<!-- wp:site-title {"level":0} /-->', 'brand-group', gap0=False, extra={'layout': {'type': 'flex', 'flexWrap': 'nowrap'}}) +
    '<!-- wp:social-links {"iconColor":"ink","iconColorValue":"#141217","className":"is-style-logos-only cc-social","layout":{"type":"flex"}} -->\n<ul class="wp-block-social-links has-icon-color is-style-logos-only cc-social"><!-- wp:social-link {"url":"https://www.instagram.com/cc_x_standert/","service":"instagram"} /--></ul>\n<!-- /wp:social-links -->' +
    BTN('Borrow a bike →', '/#fleet', 'pink').replace('<!-- wp:buttons -->', '<!-- wp:buttons {"className":"cc-borrow"} -->').replace('class="wp-block-buttons"', 'class="wp-block-buttons cc-borrow"'),
    'max cc-bar', gap0=False, extra={'layout': {'type': 'flex', 'flexWrap': 'nowrap', 'justifyContent': 'space-between'}}),
    'top', tag='header', align='full')
notice = P('We’re building our new website · until then, borrow bikes right here', 'notice mono')
open(T + 'parts/header.html', 'w').write(notice + '\n' + header + '\n')

footer = G(G(
    P('Community Connect NPC<br>Woodstock, Cape Town, ZA') +
    P('Group rides: every Friday and more, on <a href="https://www.strava.com/clubs/cc_x_standert">Strava</a><br>Questions: <a href="mailto:contact@community-connect.cc">contact@community-connect.cc</a>') +
    DET('The collective', P('<a href="/#how">How it works</a>') + P('<a href="/#deal">The deal</a>') + P('<a href="/#agreement">Borrowing agreement</a>')) +
    DET('Happenings', P('<a href="/#rides">Our rides</a>') + P('<a href="/#events">Events 2027</a>') + P('<a href="/#newsletter">Newsletter</a>') + P('<a href="https://www.instagram.com/cc_x_standert/">Instagram</a>') + P('<a href="https://www.strava.com/clubs/cc_x_standert">Strava club</a>')) +
    DET('Bikes &amp; support', P('<a href="/#fleet">The fleet</a>') + P('<a href="/#ask">Ask for any bike</a>') + P('<a href="mailto:contact@community-connect.cc">Email the crew</a>')) +
    '<!-- wp:html -->\n<ul class="cc-soc"><li><a href="https://www.instagram.com/cc_x_standert/" aria-label="Instagram"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3.2" y="3.2" width="17.6" height="17.6" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.2" cy="6.8" r=".6" fill="currentColor"/></svg></a></li><li><a href="https://www.strava.com/clubs/cc_x_standert" aria-label="Strava club"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10.4 1.8 15.9 12.6h-3.1l-2.4-4.7-2.4 4.7H4.9z"/><path d="M11.6 14.2h2.3l2.1 4.1 2.1-4.1h2.3L16 22.4z"/></svg></a></li><li><a href="mailto:contact@community-connect.cc" aria-label="Email the crew"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5.2" width="18" height="13.6" rx="2.2"/><path d="M3.6 6.6 12 13l8.4-6.4"/></svg></a></li></ul>\n<!-- /wp:html -->' +
    P('<a href="/#agreement">Borrowing agreement</a> · bikes maintained with Woodstock Cycleworks', 'fine') +
    P('© 2026 Community Connect NPC · Reg. 2025/713989/08', 'fine'),
    'max'), 'foot', tag='footer', align='full')
open(T + 'parts/footer.html', 'w').write(footer + '\n')

# ---------- templates ----------
def tpl(body):
    return ('<!-- wp:template-part {"slug":"header","area":"header","tagName":"div"} /-->\n' + body +
            '\n<!-- wp:template-part {"slug":"footer","area":"footer","tagName":"div"} /-->\n')
front = ''.join(f'<!-- wp:pattern {{"slug":"community-connect/{s}"}} /-->\n' for s in ['hero', 'welcome', 'fleet', 'fleet-banner', 'how-it-works', 'rides', 'events', 'numbers', 'deal', 'ask', 'drawings', 'agreement', 'newsletter', 'after-ride'])
open(T + 'templates/front-page.html', 'w').write(tpl(G(front, 'cc-main', tag='main', align='full', anchor='top')))
page = G(G('<!-- wp:post-title {"level":1,"className":"h big"} /-->' + '<!-- wp:post-content {"layout":{"type":"default"},"className":"cc-content"} /-->', 'in max cc-page', gap0=False), 'cc-main', tag='main', align='full')
open(T + 'templates/page.html', 'w').write(tpl(page))
open(T + 'templates/page-wide.html', 'w').write(tpl(G('<!-- wp:post-content {"layout":{"type":"default"}} /-->', 'cc-main', tag='main', align='full')))
single = G('' +
           G('<!-- wp:post-content {"layout":{"type":"default"},"className":"cc-content"} /-->', 'in max cc-bike-page', gap0=False),
           'cc-main', tag='main', align='full')
open(T + 'templates/single-cc_bike.html', 'w').write(tpl(single))
index = G(G('<!-- wp:query-title {"type":"archive","className":"h big"} /-->' +
            '<!-- wp:query {"queryId":1,"query":{"perPage":10,"pages":0,"offset":0,"postType":"post","inherit":true}} -->\n<div class="wp-block-query"><!-- wp:post-template -->\n' +
            '<!-- wp:post-title {"isLink":true,"level":2} /--><!-- wp:post-excerpt /-->\n<!-- /wp:post-template --><!-- wp:query-pagination -->\n<!-- wp:query-pagination-previous /--><!-- wp:query-pagination-next /-->\n<!-- /wp:query-pagination --></div>\n<!-- /wp:query -->',
            'in max cc-page', gap0=False), 'cc-main', tag='main', align='full')
open(T + 'templates/index.html', 'w').write(tpl(index))
single_post = G(G('<!-- wp:post-title {"level":1,"className":"h big"} /--><!-- wp:post-date /--><!-- wp:post-featured-image /--><!-- wp:post-content {"layout":{"type":"default"}} /-->', 'in max cc-page', gap0=False), 'cc-main', tag='main', align='full')
open(T + 'templates/single.html', 'w').write(tpl(single_post))
nf = G(G(H('Nothing here.', 1, 'h big') + P('The page moved or never existed. The bikes are still here though.') + BTN('See the fleet →', '/#fleet', 'pink'), 'in max cc-page', gap0=False), 'cc-main', tag='main', align='full')
open(T + 'templates/404.html', 'w').write(tpl(nf))
print('ok')
