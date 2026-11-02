"""Offline semantic contract for the public monthly archive, before and after launch."""
from datetime import date, datetime, timedelta
from html.parser import HTMLParser
import re
from urllib.parse import urlsplit
from zoneinfo import ZoneInfo


class Archive(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.depth = 0
        self.roots = 0
        self.heading = False
        self.headings = []
        self.text = []
        self.cards = []
        self.card = None
        self.anchor = None
        self.empty = False

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == 'section':
            if self.depth:
                self.depth += 1
            elif attrs.get('aria-label') == 'Published monthly roundups':
                self.depth = 1
                self.roots += 1
        if not self.depth:
            return
        classes = attrs.get('class', '').split()
        if tag == 'h1':
            self.heading = True
            self.headings.append([])
        if tag == 'section' and 'desk-empty' in classes:
            self.empty = True
        if tag == 'article' and 'desk-card' in classes:
            self.card = []
            self.cards.append(self.card)
        if tag == 'a' and self.card is not None:
            self.anchor = [attrs.get('href', ''), []]

    def handle_data(self, data):
        if not self.depth:
            return
        self.text.append(data)
        if self.heading:
            self.headings[-1].append(data)
        if self.anchor is not None:
            self.anchor[1].append(data)

    def handle_endtag(self, tag):
        if not self.depth:
            return
        if tag == 'h1':
            self.heading = False
        if tag == 'a' and self.anchor is not None:
            self.card.append(self.anchor)
            self.anchor = None
        if tag == 'article':
            self.card = None
            self.anchor = None
        if tag == 'section':
            self.depth -= 1


def monthly_archive_visible(html: str, origin: str, today: date | None = None) -> bool:
    """Accept a real empty archive or dated public edition cards, never a navigation link."""
    archive = Archive()
    archive.feed(html)
    headings = [' '.join(' '.join(parts).split()) for parts in archive.headings]
    if archive.roots != 1 or headings != ['A new month. A shared record of the last.']:
        return False
    if not archive.cards:
        text = ' '.join(' '.join(archive.text).split())
        return archive.empty and 'The first roundup is scheduled for 1 November 2026' in text
    if archive.empty:
        return False
    today = today or datetime.now(ZoneInfo('Africa/Nairobi')).date()
    previous = today.replace(day=1) - timedelta(days=1)
    base = urlsplit(origin)
    months = set()
    for links in archive.cards:
        if len(links) != 1:
            return False
        href, title = links[0]
        parsed = urlsplit(href)
        match = re.fullmatch(r'/monthly-roundup/(\d{4})-(0[1-9]|1[0-2])', parsed.path)
        if (parsed.scheme != 'https' or parsed.netloc != base.netloc
                or parsed.query or parsed.fragment or not match or not ''.join(title).strip()):
            return False
        year, month = map(int, match.groups())
        try:
            key = date(year, month, 1)
        except ValueError:
            return False
        if key < date(2026, 10, 1) or key > previous or key in months:
            return False
        months.add(key)
    return True
