**Research note.** WebSearch worked, but WebFetch was blocked for api.sejm.gov.pl, lexlege.pl and cm.twobirds.com (hosts not in the session's network allowlist). I could not read any statute text directly. Claims marked [mem] come from my own knowledge and are unverified. Check them on ISAP (isap.sejm.gov.pl) and EUR-Lex before publishing legal copy. None of this is legal advice.

## 1. Booking UX (all [mem]; no source verified)
- **Layout.** Use one page with four groups: contact, date and time, format and length, then submit. Put the "this is a request, Jacke confirms within X h and sends a payment link" line directly above the button.
- **Fields.** Required: name, email, preferred date, hour, length (45 min or other), format. Optional: phone, notes. Add `autocomplete="name"`, `"email"` and `"tel"`, and `inputmode="tel"`.
- **Format.** Use radios: "Online (Google Meet)" and "Stacjonarnie" with a sub-choice of Kramarska 33 or Żydowska 35. Show the price (150 zł) next to the length.
- **Date picker.** Offer a month grid with Previous/Next buttons and a visible "listopad 2026" heading. Disable past dates and show an "unavailable" text label, not colour alone. Keep a native `<input type="date" min=...>` as the mobile and no-JS fallback.
- **Time.** Use hour chips (`<button type="button" aria-pressed>`) or a `<select>`.
- **Validation.** Validate on blur, then on input once a field has been marked invalid. Show the error text beside the field. Wire it with `aria-invalid="true"` and `aria-describedby`. On failed submit, show an error summary (`role="alert"`, `tabindex="-1"`) and move focus to it. Never clear entered values.
- **Success.** Replace the form with a `role="status"` block and move focus to its heading. It should echo the request, state the response time and next step, and say "kopia poszła na Twój e-mail".
- **Email to the client.** Send an auto-acknowledgement with a summary of the request. Use `From: Perki <noreply@perki.pl>` and `Reply-To: Jacke <kontakt@perki.pl>`.
- **Email to the teacher.** Use `Reply-To: <client email>`.
- **Headers.** Build them in PHP as `$h=['Content-Type: text/html; charset=UTF-8','Reply-To: '.sanitize_email($e)];`. Strip CR/LF from the name.
- **Deliverability.** Set SPF, DKIM and DMARC on perki.pl.
- **Spam control.** Keep the honeypot, throttle and JSON content-type check. Avoid CAPTCHA, which conflicts with 3.3.8 if it is a cognitive test.

## 2. WCAG 2.2 AA and the EAA
Source for the three criteria below: [testparty](https://testparty.ai/blog/wcag-22-new-success-criteria) and [Deque](https://dequeuniversity.com/resources/wcag-2.2). The rest is [mem]; the normative text is at w3.org/TR/WCAG22/.

- **2.5.8 Target Size (Minimum), AA.** Targets must be at least 24×24 CSS px, or have enough spacing. Use 44×44 px for calendar cells and chips on mobile. That size is the AAA criterion 2.5.5, so it is a recommendation, not an AA requirement.
- **3.3.7 Redundant Entry, A.** Don't re-ask for the same data in one flow, and don't wipe valid data after an error.
- **2.4.11 Focus Not Obscured (Minimum), AA.** A sticky header or bar must not hide the focused control. Use `scroll-margin-top`.
- **2.4.13 Focus Appearance is AAA, not AA.** One search result listed it as AA; that is wrong. Still ship a visible focus style, e.g. `:focus-visible{outline:3px solid #1a56db;outline-offset:2px}`, which also satisfies 2.4.7 (AA).
- **3.3.8 Accessible Authentication, AA.** Not relevant, unless a CAPTCHA is added.
- **Also relevant:**
  - 1.3.5 (autocomplete tokens).
  - 3.3.1 / 3.3.2 / 3.3.3 (visible `<label>`, not placeholder-only; error text with a suggestion).
  - 4.1.3 (status messages via `role="status"` or `aria-live="polite"`; errors via `role="alert"`).
  - 1.4.11 (3:1 contrast for borders and selected state).
  - 1.4.10 (reflow at 320 px).
  - 2.5.7 (no drag-only interaction).
  - 3.2.6 (Consistent Help is Level A; one search result listed it as AAA, which is wrong).
- **Calendar markup.** Use `aria-pressed="true"` on the selected date or hour button, and `aria-current="date"` on today. Put `aria-live="polite"` on the month heading. Give the arrows `aria-label="Poprzedni miesiąc"` and `"Następny miesiąc"`. A full `role="grid"` with arrow-key navigation (ARIA APG date-picker pattern) is only needed if you want grid semantics; a button grid with natural Tab order is simpler and acceptable.
- **EAA in Poland.** The act of 26 April 2024 on ensuring accessibility of certain products and services applies from 28 June 2025 ([gov.pl](https://www.gov.pl/web/funds-regional-policy/another-step-towards-accessibility), [PARP](https://www.parp.gov.pl/component/content/article/88869:opublikowano-polski-akt-o-dostepnosci)). Microenterprises providing services (fewer than 10 staff and turnover or balance sheet of €2M or less) are exempt from the service requirements ([Roedl](https://www.roedl.pl/en/good-to-know/good-to-know/secure-e-commerce/ensuring-access-to-websites-and-products-obligation-for-enterprises), [fashionbiznes](https://fashionbiznes.pl/ostatni-dzwonek-dla-e-commerce-beda-surowe-kary-za-brak-dostepnosci/)). Treat perki.pl as exempt, but the Polish statutory wording was not verified. Aim for WCAG 2.2 AA anyway.

## 3. GDPR / RODO
Sources disagree on the legal basis. One guide uses art. 6(1)(f) for a contact form and art. 6(1)(b) for contract-linked data ([kluczesoft](https://kluczesoft.pl/wiedza/bezpieczenstwo/klauzula-informacyjna-rodo)). I found no authoritative UODO text.

- **Legal basis.** Use art. 6(1)(b), "steps at the request of the data subject prior to entering into a contract", for a booking request. Use 6(1)(f) for generic inquiries. **No consent checkbox is needed.** Consent can't be bundled where another basis applies.
- **Marketing.** Add a separate, unticked, optional checkbox only if you want newsletters.
- **Terms.** "Akceptuję regulamin" is a terms acceptance, not a GDPR consent. Keep it separate.
- **Where to show the clause.** Show it before submit. Put a 2-line layered notice under the button, linking to the full polityka prywatności.
- **Art. 13 content.**
  - Controller identity and contact (Jacke Sienkiewicz, business address, email).
  - Purposes and legal basis.
  - Recipients (hosting, email provider, Google Meet/Workspace). Say if data goes outside the EEA.
  - Retention period.
  - Rights: access, rectification, erasure, restriction, objection (if basis is 6(1)(f)), portability.
  - Right to complain to UODO.
  - Whether providing data is voluntary and what happens if you don't (no booking).
  - No automated decision-making.
- **Retention.** Suggested: until the request is handled, then the claim-limitation period (~3 years for business claims, 5 years for accounting records) [mem].
- **Draft notice.** "Administratorem danych jest Jacke Sienkiewicz, [adres]. Dane przetwarzam, aby odpowiedzieć na zapytanie i umówić lekcję (art. 6 ust. 1 lit. b RODO), do czasu realizacji i przedawnienia roszczeń. Masz prawo dostępu, sprostowania, usunięcia, ograniczenia, przenoszenia oraz skargi do Prezesa UODO. Podanie danych jest dobrowolne, ale konieczne do umówienia lekcji. [Polityka prywatności]"

## 4. Price display (Omnibus)
- **Correction to the premise.** Art. 6a is the **Directive 98/6/EC** provision, added by Omnibus Directive 2019/2161 and applicable since 28 May 2022 ([poradnikprzedsiebiorcy](https://poradnikprzedsiebiorcy.pl/-dyrektywa-omnibus-a-oferowane-promocje-i-obnizki), [inforlex: Commission guidelines 2021/C 526/02](https://www.inforlex.pl/download/akty_pdf,E0C,2021,526,130.pdf)). The Polish provision is **art. 4 ust. 1–2** of the ustawa o informowaniu o cenach towarów i usług (Dz.U. 2023 poz. 168). UOKiK decisions cite that article ([DIH-3.95.2024](https://decyzje.uokik.gov.pl/bp/dec_prez.nsf/43104c28a7a1be23c1257eac006d8dd4/6bc9d7ffc950ac55c1258beb00173403/$FILE/DECYZJA%20DIH-3.95.2024%20uchylenie%20decyzji%20WIIH%20i%20orzeczenie%20co%20do%20istoty_ceny.pdf)). It covers services too ([DIH-3.110.2024](https://decyzje.uokik.gov.pl/bp/dec_prez.nsf/43104c28a7a1be23c1257eac006d8dd4/c63dfe4425b174f0c1258beb0017d8c6/$FILE/DECYZJA%20DIH-3.110.2024%20uchylenie%20decyzji%20WIIH%20i%20orzeczenie%20co%20do%20istoty_ceny.pdf)).
- **What counts as a reduction.** UOKiK's position is that a struck-through reference price must be the lowest price of the last 30 days, worded "najniższa cena w okresie 30 dni przed wprowadzeniem obniżki". Wording such as "cena referencyjna" or "poprzednia cena" was rejected ([pit.pl](https://www.pit.pl/?p=4698), [prawo.pl](https://www.prawo.pl/biznes/jaki-sa-zasady-informowania-o-cenach-towarow,519631.html)).
- **Your case.** I found no source on bundles. 600 zł is the sum of four single lessons at the standing 150 zł price, not a prior price of the pack. Whether that is a "reduction" is uncertain; the Commission guidelines distinguish other price-advantage techniques from reductions. Do not rely on the unverified reading.
- **Safest wording.**
  - Drop the strikethrough. Show "Pakiet 4 lekcji: 570 zł (142,50 zł za lekcję)", "Pojedyncza lekcja: 150 zł" and "Pakiet 8: 1080 zł. Pakiet 12: 1530 zł."
  - Present the tier discount as a permanent price list, not a promotion.
  - If you ever run a time-limited promo, add "Najniższa cena w okresie 30 dni przed wprowadzeniem obniżki: X zł" right next to the struck price, legible and in contrast (UOKiK also criticises tiny fonts and low contrast).
  - Keep price changes logged for 30 days.

## 5. Withdrawal (14 days) and the 24h policy
- **Right.** Consumers have 14 days to withdraw from distance contracts without giving a reason (art. 27 ustawy o prawach konsumenta; [fakturaxl](https://www.fakturaxl.pl/odstapienie-od-umowy-zawartej-na-odleglosc)). If you fail to inform them, the period extends by 12 months ([rp.pl](https://pro.rp.pl/prawo-w-firmie/art12118481-nowa-ustawy-o-prawach-konsumenta-dluzszy-czas-na-odstapienie-od-umowy)).
- **Loss of the right.** Under art. 38 pkt 1 the consumer loses it only after the service is **fully** performed, with prior express consent and an acknowledgement of that loss ([lexlege](https://www.lexlege.pl/ustawa-o-prawach-konsumenta/art-38/), [standardyprawa](https://standardyprawa.pl/akt/51/art/9883)). A 4-, 8- or 12-lesson pack is not fully performed until the last lesson, so withdrawal stays open for 14 days from contract conclusion.
- **If the first lesson falls inside the 14 days.** The consumer must make an explicit request to start, and then pays proportionally for what was delivered (art. 15 / 35) [mem]. For example, after one lesson of a pack of 4, they pay 142,50 zł and get the rest refunded.
- **Pkt 12 exemption.** It covers leisure, sport and cultural services with a specified date ([CTS Eventim C-96/21](https://uokik.gov.pl/Download/1858) context). Whether music lessons fall under it is contested. Don't rely on it.
- **What to state, before the consumer is bound and again in a confirmation on a durable medium (email).**
  - The 14-day right, how to exercise it, and a model withdrawal form (Annex 2 to the act).
  - The proportional-payment rule.
  - A checkbox or confirmation for "chcę rozpocząć świadczenie przed upływem 14 dni".
  - The total price including all costs.
  - An "obowiązek zapłaty" label on the payment button (art. 17 ust. 3 [mem]).
  - Because the contract is concluded when the teacher confirms and sends the link, put all of this on the payment page and in the confirmation email, not only on the request form.
- **24h reschedule policy.** It does not conflict with withdrawal. It governs changing a booked lesson, not cancelling the contract. Withdrawal is a separate right that can't be removed or limited by the policy.
- **What the policy can say.** Say plainly that a lesson cancelled with less than 24h notice counts as delivered. That is a normal commercial term, but keep it transparent. A disproportionate forfeiture could be challenged as an abusive clause (art. 385³ KC) [mem].
- **Uncertain.** I found no source on packs and 24h rules specifically. Have a lawyer or consumer advisor review the regulamin.