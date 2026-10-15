# Memory snapshot (generated 2026-10-14, for labeling)

What the assistant can draw on right now. Judge each answer against this. Regenerate before a labeling session if facts change. Local use only: includes restricted literals.

## Facts (live, trusted)

| id | scope | text |
| --- | --- | --- |
| 73 | case | The redesigned events calendar launches on 20 October. |
| 79 | case | Northlight Digital is building the events calendar with Views and a recurring-dates field. |
| 81 | case | The old events page will redirect to the new calendar on launch day. |
| 84 | case | The events calendar redesign was approved by the trustees on 3 September. |
| 70 | role | Only the Communications Manager can publish press releases. |
| 76 | role | Events must be published at least two weeks before they take place. |
| 80 | role | Editors must add alt text to every image before publishing. |
| 93 | role | News posts need a summary of no more than 160 characters. |
| 68 | site | The makerspace has two 3D printers, and users must complete a 20-minute induction before using them. |
| 71 | site | Only assistance dogs are allowed inside the library. |
| 74 | site | Code Club for ages 9 to 13 meets every Thursday at 4pm in the makerspace. |
| 75 | site | Harbourside Community Library is open Monday to Friday from 9am to 8pm, Saturday from 10am to 5pm, and Sunday from 12pm to 4pm. |
| 77 | site | Baby Rhyme Time runs every Tuesday at 10:30am in the Children's Room. |
| 78 | site | The library is closed on public holidays and on the last Friday of every month for staff training. |
| 82 | site | The library website is built on Drupal 11 and maintained by the agency Northlight Digital. |
| 83 | site | The library's writing style is warm and plain English with no jargon, and it addresses readers as 'you'. |
| 86 | site | Free Wi-Fi is available in the building on the network Harbourside_Public, and it needs no password. |
| 87 | site | You can reach the library by phone or at hello@harbourside-library.example. |
| 89 | site | The building is step-free, there is a hearing loop at the main desk, and large-print and audio books are shelved by the entrance. |
| 90 | site | The annual book sale is held on the first weekend of June in the main hall. |
| 91 | site | Borrowing history is deleted 30 days after an item is returned, unless the member opts in to keep it. |
| 92 | site | The library stopped charging overdue fines in 2022, and items not returned after 8 weeks are billed at replacement cost. |
| 94 | site | Northlight Digital provides website support on weekdays from 9am to 5pm, and urgent problems go to the on-call number. |
| 95 | site | Public computers can be booked for 60 minutes at a time, and printing costs 10p per black-and-white page. |
| 96 | site | Members can borrow up to 20 items at a time for 3 weeks, and can renew twice online unless another member has reserved the item. |
| 97 | site | Membership is free for residents of the borough, and children under 16 need a parent or guardian to sign up. |
| 98 | site | Community groups can book a meeting room for free, for up to 4 hours at a time. |
| 117 | site | Email the balls dept on [literal:balls_email] |
| 69 | user | Sam Okafor is the library's Head of Digital and the site administrator. |
| 72 | user | Sam is presenting the new events calendar to the trustees on 14 October. |
| 85 | user | Sam wants the weekly website analytics summary on Monday mornings. |
| 88 | user | Sam prefers short answers as bullet points. |
|  |  |  |

## Literals (exact values the assistant can look up)

| key | type | restricted | value | what it is |
| --- | --- | --- | --- | --- |
| accounts_phone | text | no | +1 555 0103 | The phone number to call about fines and billing, ALSO card charges and the finance department |
| balls_email | token | yes | [config_page:site_shit:aimemail] | the most educate email address |
| cfg_phone | token | no | [config_page:contact:field_phone] | NULL |
| login | url | no | /user/login | The page where members sign in to their account, ALSO log in to check loans, fines or reservations |
| main_phone | text | no | +44 1223 000000 | The library main phone number, ALSO customer service and speaking to a librarian |
| my_account | url | yes | /user | The page where a signed-in member sees their own account, ALSO check loans, fines or personal details |
| password_reset | url | no | /user/password | The page to reset a forgotten password, ALSO recover access to an account |
| reservations_phone | text | no | +1 555 0102 | The phone number for booking a meeting room or a study space, ALSO room reservations |
| site_name | token | yes | [site:name] | The name of the website |
| staff_line | text | yes | +44 1223 000111 | The phone number staff use to reach the library back office |
| welcome2 | content | no | node:1 | the welcome page of the site |
|  |  |  |  |  |
