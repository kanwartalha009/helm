#!/usr/bin/env python3
"""
Build the design documentation PDF required by the Google Ads API "Apply for Standard access"
form (question 8: "Provide design documentation of your tool").

Every factual claim in this document was read out of the Helm codebase, not assumed:
  - the eight GAQL report resources come from `grep -o "FROM [a-z_]*" api/app/Platforms/Google/`
  - the read-only claim comes from there being no *Mutate* call anywhere in app/Platforms/Google/
  - the two API services used are GoogleAdsService.search and CustomerService.listAccessibleCustomers

Reviewers reject applications that are vague or that claim capabilities the tool does not have.
This says exactly what Helm does and nothing more.

    python3 scripts/build_google_api_design_doc.py
"""

from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.units import mm
from reportlab.lib import colors
from reportlab.platypus import (
    SimpleDocTemplate, Paragraph, Spacer, Table, TableStyle, PageBreak, ListFlowable, ListItem,
)

OUT = "Helm-Google-Ads-API-Design-Documentation.pdf"

# ── Styles: plain, dense, professional. A reviewer is skimming, not admiring. ──────────────
styles = getSampleStyleSheet()

H1 = ParagraphStyle("H1", parent=styles["Heading1"], fontSize=16, spaceBefore=14, spaceAfter=8,
                    textColor=colors.HexColor("#111827"))
H2 = ParagraphStyle("H2", parent=styles["Heading2"], fontSize=12, spaceBefore=12, spaceAfter=6,
                    textColor=colors.HexColor("#111827"))
BODY = ParagraphStyle("Body", parent=styles["Normal"], fontSize=9.5, leading=14, spaceAfter=6)
SMALL = ParagraphStyle("Small", parent=BODY, fontSize=8.5, textColor=colors.HexColor("#4B5563"))
TITLE = ParagraphStyle("TitleX", parent=styles["Title"], fontSize=22, spaceAfter=4,
                       textColor=colors.HexColor("#111827"))
SUB = ParagraphStyle("Sub", parent=styles["Normal"], fontSize=11, alignment=0,
                     textColor=colors.HexColor("#4B5563"), spaceAfter=18)

TBL = TableStyle([
    ("BACKGROUND", (0, 0), (-1, 0), colors.HexColor("#F3F4F6")),
    ("TEXTCOLOR", (0, 0), (-1, 0), colors.HexColor("#111827")),
    ("FONTNAME", (0, 0), (-1, 0), "Helvetica-Bold"),
    ("FONTSIZE", (0, 0), (-1, -1), 8.5),
    ("LEADING", (0, 0), (-1, -1), 11),
    ("VALIGN", (0, 0), (-1, -1), "TOP"),
    ("GRID", (0, 0), (-1, -1), 0.4, colors.HexColor("#D1D5DB")),
    ("TOPPADDING", (0, 0), (-1, -1), 5),
    ("BOTTOMPADDING", (0, 0), (-1, -1), 5),
    ("LEFTPADDING", (0, 0), (-1, -1), 6),
    ("RIGHTPADDING", (0, 0), (-1, -1), 6),
])


def p(t, s=BODY):
    return Paragraph(t, s)


def bullets(items):
    return ListFlowable(
        [ListItem(p(i), leftIndent=12) for i in items],
        bulletType="bullet", start="•", leftIndent=14, bulletFontSize=8,
    )


story = []

# ── Cover ────────────────────────────────────────────────────────────────────────────────
story += [
    p("Helm", TITLE),
    p("Design documentation — Google Ads API Standard access application", SUB),
]

story.append(Table([
    ["Tool name", "Helm"],
    ["Owner", "Nova Solution"],
    ["Type", "Internal, private reporting dashboard (not distributed, not resold to advertisers)"],
    ["Users", "Employees of the agency that owns the Google Ads manager account (MCC)"],
    ["API usage", "READ-ONLY reporting. No campaign, ad, budget or account is created or modified."],
    ["Services used", "GoogleAdsService.Search; CustomerService.ListAccessibleCustomers"],
    ["Mutate services used", "None."],
], colWidths=[38 * mm, 122 * mm], style=TBL))

story += [
    Spacer(1, 14),
    p("1. What the tool is", H1),
    p(
        "Helm is an internal analytics dashboard used by a single marketing agency to report on the "
        "advertising performance of the client stores it manages. It consolidates three data sources "
        "into one view per client brand: e-commerce revenue from Shopify, advertising cost and "
        "performance from Google Ads, Meta and TikTok, and the resulting return on ad spend."
    ),
    p(
        "Every Google Ads account it reads is a client account that sits under the agency's own "
        "Google Ads manager account (MCC). The agency already has full access to these accounts "
        "through the Google Ads UI. Helm does not add access; it aggregates reporting that would "
        "otherwise be gathered by opening each account by hand, every morning."
    ),
    p(
        "The tool is used only by the agency's own staff. It is not offered to advertisers, not sold "
        "as a product, and has no public sign-up. There is no path by which a third party can supply "
        "their own Google Ads account to it."
    ),

    p("2. The problem it solves", H1),
    p(
        "The agency manages advertising for a large number of direct-to-consumer e-commerce brands. "
        "Reporting on them individually means opening Google Ads, Meta Ads Manager, TikTok Ads "
        "Manager and Shopify separately for every brand, then reconciling spend against revenue by "
        "hand in a spreadsheet. That is slow, error-prone, and impossible to do daily at this scale."
    ),
    p(
        "Helm performs that consolidation automatically once a day and presents one figure the agency "
        "actually manages against: revenue, advertising cost, and return on ad spend, per brand, per "
        "day, in the brand's own currency and time zone."
    ),

    p("3. How the Google Ads API is used", H1),
    p(
        "Authentication is via a single OAuth2 refresh token belonging to the agency's manager "
        "account. The manager account ID is sent as <b>login-customer-id</b> on every request. Client "
        "accounts are enumerated from that manager account; no credentials belonging to any "
        "advertiser are stored."
    ),
    p(
        "All reporting is retrieved through <b>GoogleAdsService.Search</b> using GAQL. The complete set "
        "of resources the tool queries is listed below — this is the full extent of the API surface it "
        "touches."
    ),
    Spacer(1, 4),
]

story.append(Table([
    ["GAQL resource", "What it is read for"],
    ["customer", "Account-level cost, impressions, clicks, conversions and conversion value, by day."],
    ["campaign", "Campaign-level cost and performance by day, plus campaign name, status and channel type."],
    ["ad_group", "Ad-group-level performance, to break a campaign down one level."],
    ["asset_group", "The Performance Max equivalent of an ad group, for the same breakdown."],
    ["ad_group_ad", "Ad final URLs, used to attribute advertising cost to the specific Shopify product\n"
                    "the ad points at."],
    ["customer_client", "Enumerates the client accounts under the manager account, so a user can pick\n"
                        "which account belongs to which brand."],
    ["geographic_view", "Performance split by country, for geographic reporting."],
    ["geo_target_constant", "Reference data: maps a country criterion ID to its ISO country code.\n"
                            "Cached for 30 days; it does not change."],
], colWidths=[38 * mm, 122 * mm], style=TBL))

story += [
    Spacer(1, 10),
    p("3.1 The tool is strictly read-only", H2),
    p(
        "Helm issues no mutate operations of any kind. It does not create, update, pause, remove or "
        "budget any campaign, ad group, ad, keyword, audience or account. There is no code path in the "
        "application that constructs a Google Ads mutate request, and no user interface control that "
        "could trigger one. Its Google Ads integration is confined to a single client class whose only "
        "two operations are a GAQL search and an accessible-customers listing."
    ),
    p(
        "This is a deliberate product constraint, not an omission: the agency changes campaigns in the "
        "Google Ads UI, and Helm exists to measure the result. Writing to the ad platform is explicitly "
        "outside its scope."
    ),

    p("4. Campaign types supported", H1),
    p(
        "Helm reports on whichever campaign types the agency's client accounts run; it is not written "
        "against any one type. In practice these are <b>Search, Performance Max, Shopping, Display, "
        "Video and Demand Gen</b>. Campaigns are read generically via the campaign resource and labelled "
        "with their advertising channel type, and Performance Max is additionally handled via the "
        "asset_group resource, which is its ad-group analogue."
    ),

    p("5. Data flow", H1),
    p(
        "A scheduled job runs once per day, per brand, per platform. For Google Ads it requests the "
        "resources listed in section 3 for the trailing seven days, normalises cost from micros into "
        "the account's currency, converts to a common reporting currency using a stored exchange rate, "
        "and writes one row per brand per day into the application's own database. The dashboard is "
        "served from that database, so opening a page issues no Google Ads API calls."
    ),
    Spacer(1, 2),
    bullets([
        "<b>Ingest</b> — a daily scheduled job reads reporting data for the trailing seven days.",
        "<b>Normalise</b> — cost_micros is divided by 1,000,000; currency and time zone are resolved "
        "per account; a failed pull is recorded as incomplete rather than as zero.",
        "<b>Store</b> — results are written to the application's own database (one row per brand, "
        "platform and day).",
        "<b>Present</b> — a web dashboard reads only from that database. No user action calls the "
        "Google Ads API.",
    ]),

    PageBreak(),

    p("6. Why Standard access is required", H1),
    p(
        "The agency manages a large number of client accounts under one manager account. Because the "
        "Google Ads API operation quota is scoped to the <b>developer token</b>, every client account "
        "shares a single daily budget. Under Basic access (15,000 operations per day) that budget is "
        "consumed by the daily reporting run across all accounts, after which the token is rate-limited "
        "and reporting for the remaining accounts fails."
    ),
    p(
        "The volume is a function of the number of managed accounts, not of inefficient querying. The "
        "tool already: batches by date range rather than per day where the API allows it; caches "
        "immutable reference data (geo_target_constant) for 30 days rather than re-requesting it; "
        "stops issuing requests immediately upon a RESOURCE_EXHAUSTED response and honours the "
        "retry delay Google returns, rather than retrying against an exhausted quota; and serves all "
        "user-facing pages from its own database so that browsing the dashboard costs no API calls."
    ),
    p(
        "Standard access is requested so that daily reporting can complete for every managed account. "
        "The tool's access requirements will not grow beyond read-only reporting."
    ),

    p("7. Access, security and compliance", H1),
    bullets([
        "<b>Not accessible outside the organisation.</b> Only employees of the agency can sign in. "
        "There is no public registration.",
        "<b>Not a third-party tool.</b> Helm is developed and operated by the same organisation that "
        "owns the manager account and the developer token.",
        "<b>Credentials.</b> One OAuth2 refresh token for the manager account, stored encrypted at "
        "rest in the application database. No advertiser supplies credentials to the tool.",
        "<b>Data shown.</b> Only aggregated performance metrics (cost, impressions, clicks, "
        "conversions, conversion value) and campaign metadata. No personally identifiable "
        "information is requested or stored.",
        "<b>Data sharing.</b> Google Ads data is not sold, shared with third parties, or combined "
        "with data from other advertisers. Each brand's data is visible only to the agency staff "
        "assigned to that brand.",
        "<b>Retention.</b> Reporting data is retained only for the historical charts in the "
        "dashboard, and can be deleted per brand on request.",
    ]),

    p("8. Answers to the application questions", H1),
]

story.append(Table([
    ["Question", "Answer"],
    ["Accessible to users outside your organisation?", "No."],
    ["Using the token with a tool built by someone else?", "No — built and operated in-house."],
    ["Campaign types supported", "Search, Performance Max, Shopping, Display, Video, Demand Gen\n"
                                 "(reporting is channel-agnostic)."],
    ["Capabilities provided", "Reporting ONLY.\n"
                              "Not account creation, account management, campaign creation,\n"
                              "campaign management, or keyword planning."],
], colWidths=[62 * mm, 98 * mm], style=TBL))

story += [
    Spacer(1, 10),
    p(
        "Note on capabilities: Helm does not use the keyword planning services and does not perform "
        "account or campaign management of any kind. Only <b>Reporting</b> applies.",
        SMALL,
    ),
]

doc = SimpleDocTemplate(
    OUT, pagesize=A4,
    leftMargin=22 * mm, rightMargin=22 * mm, topMargin=20 * mm, bottomMargin=18 * mm,
    title="Helm — Google Ads API Design Documentation",
    author="Nova Solution",
)
doc.build(story)
print(f"wrote {OUT}")
