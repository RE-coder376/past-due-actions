"""Wrap site/index.html into a standalone page for GitHub Pages.

The source is written artifact-shaped - no doctype, html, head or body, because
the artifact host supplies those. A static host does not, so the same source is
wrapped here rather than maintained twice and allowed to drift.

Adds what a page that lives or dies by search needs and an artifact does not:
canonical URL, Open Graph and Twitter cards, and FAQPage structured data built
from the questions already in the markup.

    python build-site.py            # writes docs/index.html
"""

import html
import pathlib
import re
import sys

SITE = "https://re-coder376.github.io/past-due-actions/"
SRC = pathlib.Path("site/index.html")
OUT = pathlib.Path("docs/index.html")

ZIP_URL = "https://github.com/RE-coder376/past-due-actions/raw/main/dist/past-due-actions.zip"


def split_head_body(source: str):
    """Everything before the page wrapper is head material; the rest is body."""
    marker = '<div class="wrap">'
    i = source.index(marker)
    return source[:i], source[i:]


def faq_schema(body: str) -> str:
    """Build FAQPage JSON-LD from the .qa blocks already written in the page.

    Generated from the real markup rather than kept in a parallel list, so the
    structured data cannot claim a question the page does not answer.
    """
    qas = re.findall(
        r'<div class="qa">\s*<h3>(.*?)</h3>\s*<p>(.*?)</p>',
        body,
        re.S,
    )
    if not qas:
        return ""

    def clean(fragment: str) -> str:
        text = re.sub(r"<[^>]+>", "", fragment)
        text = html.unescape(text)
        return " ".join(text.split())

    items = []
    for question, answer in qas:
        items.append(
            '{"@type":"Question","name":%s,'
            '"acceptedAnswer":{"@type":"Answer","text":%s}}'
            % (_json_str(clean(question)), _json_str(clean(answer)))
        )
    return (
        '<script type="application/ld+json">'
        '{"@context":"https://schema.org","@type":"FAQPage","mainEntity":[%s]}'
        "</script>" % ",".join(items)
    )


def _json_str(value: str) -> str:
    escaped = value.replace("\\", "\\\\").replace('"', '\\"')
    return '"%s"' % escaped


def main() -> int:
    if not SRC.exists():
        print("missing %s" % SRC, file=sys.stderr)
        return 1

    source = SRC.read_text(encoding="utf-8")
    head_src, body_src = split_head_body(source)

    # The install button points at wordpress.org, which does not exist until the
    # directory approves the plugin. On the public site it has to work today.
    body_src = body_src.replace(
        'href="https://wordpress.org/plugins/past-due-actions/" id="install-link"',
        'href="%s" id="install-link"' % ZIP_URL,
    )
    body_src = body_src.replace(
        '<a href="https://wordpress.org/plugins/past-due-actions/">Past-Due Actions</a>',
        '<a href="https://github.com/RE-coder376/past-due-actions">Past-Due Actions</a>',
    )

    title = re.search(r"<title>(.*?)</title>", head_src, re.S)
    desc = re.search(r'<meta name="description" content="(.*?)">', head_src, re.S)
    title_text = title.group(1).strip() if title else "Past-Due Actions"
    desc_text = desc.group(1).strip() if desc else ""

    # The title is literally a quoted error message, so it contains double quotes.
    # Dropped into content="..." unescaped they close the attribute early and the
    # tag silently becomes empty - which is how og:title shipped blank the first
    # time. Element content keeps the real quotes; attributes get escaped.
    title_attr = html.escape(title_text, quote=True)
    desc_attr = html.escape(desc_text, quote=True)

    # Strip the two tags out of the head fragment; they are re-emitted in order below.
    head_rest = head_src
    if title:
        head_rest = head_rest.replace(title.group(0), "")
    if desc:
        head_rest = head_rest.replace(desc.group(0), "")

    page = f"""<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{title_text}</title>
<meta name="description" content="{desc_attr}">
<link rel="canonical" href="{SITE}">
<meta property="og:type" content="article">
<meta property="og:title" content="{title_attr}">
<meta property="og:description" content="{desc_attr}">
<meta property="og:url" content="{SITE}">
<meta name="twitter:card" content="summary">
<meta name="twitter:title" content="{title_attr}">
<meta name="twitter:description" content="{desc_attr}">
<link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 16 16%22><text y=%2213%22 font-size=%2213%22>&#9201;</text></svg>">
{faq_schema(body_src)}
{head_rest.strip()}
</head>
<body>
{body_src.strip()}
</body>
</html>
"""

    OUT.parent.mkdir(parents=True, exist_ok=True)
    OUT.write_text(page, encoding="utf-8")
    print("wrote %s (%d bytes)" % (OUT, len(page)))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
