"""Offline archive acceptance across first publication and month/year boundaries."""
from datetime import date
from pathlib import Path
import sys
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'deploy/aws'))
from monthly_archive_contract import monthly_archive_visible

BASE = 'https://www.waithakandegwa.com'
HEADER = '<section aria-label="Published monthly roundups"><h1>A new month.<br>A shared record of the last.</h1>'
EMPTY = '<section class="desk-empty">The first roundup is scheduled for 1 November 2026, reviewing October’s public updates.</section>'


def card(month='2026-10', title='Welcome to November', origin=BASE):
    return f'<article class="desk-card"><h2><a href="{origin}/monthly-roundup/{month}">{title}</a></h2></article>'


class Contract(unittest.TestCase):
    def accepts(self, body, today=date(2026, 11, 2)):
        return monthly_archive_visible(body, BASE, today)

    def test_first_scheduled_empty_archive(self):
        self.assertTrue(self.accepts(HEADER + EMPTY + '</section>'))

    def test_first_published_edition_has_no_empty_schedule_message(self):
        self.assertTrue(self.accepts(HEADER + card() + '</section>'))

    def test_month_and_year_rollover(self):
        self.assertTrue(self.accepts(HEADER + card('2026-12') + card('2026-11') + '</section>', date(2027, 1, 1)))

    def test_missing_or_duplicate_archive_landmark(self):
        self.assertFalse(self.accepts('<h1>A new month.</h1>' + EMPTY))
        self.assertFalse(self.accepts(HEADER + card() + '</section>' + HEADER + EMPTY + '</section>'))

    def test_future_invalid_and_prelaunch_months(self):
        for month in ['2026-11', '2027-01', '2026-13', '2026-00', '2026-09', '0000-01']:
            with self.subTest(month=month):
                self.assertFalse(self.accepts(HEADER + card(month) + '</section>'))

    def test_navigation_or_private_link_is_not_a_published_card(self):
        self.assertFalse(self.accepts(HEADER + f'<nav><a href="{BASE}/monthly-roundup/2026-10">October</a></nav></section>'))
        for month in ['2026-10?token=private', '2026-10#preview', '../write/monthly-roundup']:
            self.assertFalse(self.accepts(HEADER + card(month) + '</section>'))

    def test_foreign_origin_empty_title_and_duplicate_month(self):
        self.assertFalse(self.accepts(HEADER + card(origin='https://other.invalid') + '</section>'))
        self.assertFalse(self.accepts(HEADER + card(title=' ') + '</section>'))
        self.assertFalse(self.accepts(HEADER + card() + card() + '</section>'))

    def test_populated_and_empty_states_cannot_be_mixed(self):
        self.assertFalse(self.accepts(HEADER + card() + EMPTY + '</section>'))


if __name__ == '__main__':
    unittest.main()
