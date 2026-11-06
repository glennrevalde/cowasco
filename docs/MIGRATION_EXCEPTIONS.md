# Migration exceptions list

From the cut-over rehearsal on the real data: the cooperative sample database (`database/accounting_system.sql` of the
supplied cooperative ZIP) copied into a new target with `tools/create_target.php`, then the former water database
(Marigondon NHS and STEC-STHS Multipurpose Cooperative: 6,888 water-service accounts, 361 legacy members, 335 savings
accounts) migrated with `tools/migrate_water.php --cutover=2026-10-14`. Logs: `tests/water/evidence/cutover_both/`.
Nothing here stopped the migration (the verification is PASS); each item is a decision or a correction for a person.

The list holds names from the former database: keep it inside the cooperative.

## Summary and what to do

| Group | Items | What it means | What to do (where) |
|---|---:|---|---|
| Chart of accounts | 3 | Two accounts the former program used as the **A/R account of a bill code** are not receivables (`14183 A/D - Store & Office Equipment`, `40128 Service Fee`); one account's code says Income while the former program listed it under assets (`40202 Investment in Sibonga Mpc`). | Correct the bill codes (below); review 40202 on Chart of Accounts. |
| Default accounts | 2 | The former program had no penalty or compromise income account. | Set them on Water Settings > Collection Policy (or Module Default Accounts > Water) before charging a penalty. |
| Users | 7 | Six operators had plain-text passwords: they were hashed and each must choose a new password at the first login. One system operator got no user. | Tell the users; nothing to fix. |
| Page access | 6 | Six former profiles had no page permissions; their roles ("Water: Loan Officer", "Water: Bookkeeper", ...) have no pages. | Give them pages on User Role Configuration if they are still used. |
| Items | 1 | The WATER METER bill code got its item in the one item master (quantity 0; the former program held no stock). | Review the item's accounts on the Item List; receive meters through Vendors. |
| Bill codes | 2 | MEMBERSHIP FEE and WATER METER point their A/R at a non-receivable account. | Bill Codes: choose an Accounts Receivable account. Until then bills of these codes are refused (nothing posts to a wrong account). |
| Water accounts | 2 | Accounts 41902 and 31856 have no rate code in the former database. | Assign the rate code on Water Service Accounts (the former program could not bill them either). |
| Legacy membership | 19 | The former database also holds a cooperative membership register (361 members, 335 savings accounts, 28,713,513.24 at the cut-over). Not imported by default because the cooperative database may already hold them. 15 members have a status code the former database does not define (brought in as applicants), 2 have type 1 (taken as associate members, to confirm), one has no first name. | If the cooperative database does not hold these members: run the membership phase with `--import-membership` (see `docs/DEPLOYMENT_XAMPP.md`, step 7), then set the listed statuses on Member Information. The CSV files of the run list every member and balance. |
| Mapping check | 6 | The four bill-code problems above, and the two collection-policy accounts. | As above; the Account Mapping Validation report shows the list live. |

Also to decide after the run (not an exception, a review queue): **1,920 possible duplicate pairs** on Parties >
Identity Review (one person with several connections, or a water customer who is also a member, recorded more than
once). They are never merged automatically.

## The full list (as written by the run)

```
-- chart of accounts (3)
   Account 14183 A/D - Store & Office Equipment is used as the A/R account of a bill code or the default A/R setting although it is not a receivable; imported as Fixed Asset (what it is). The bill code is listed in the mapping exceptions and cannot post until its A/R account is corrected on the Bill Codes page.
   Account 40128 Service Fee is used as the A/R account of a bill code or the default A/R setting although it is not a receivable; imported as Income (what it is). The bill code is listed in the mapping exceptions and cannot post until its A/R account is corrected on the Bill Codes page.
   Account 40202 Investment in Sibonga Mpc: the code says Income but the former program listed it under Asset (BaseAccnt 10000); imported as Income (by code). Review on Chart of Accounts.
-- default accounts (2)
   water_penalty stays unset (the former program had no value for it). Set it on Chart of Accounts > Module Default Accounts (Water) when needed.
   water_compromise stays unset (the former program had no value for it). Set it on Chart of Accounts > Module Default Accounts (Water) when needed.
-- users (7)
   User 'Noel' (#24): the former program stored a plain-text password; it was hashed and the user must change it at the first login.
   User 'Glen' (#25): the former program stored a plain-text password; it was hashed and the user must change it at the first login.
   User 'mildred' (#26): the former program stored a plain-text password; it was hashed and the user must change it at the first login.
   Operator 4 'legacy_operator_4' was a system operator: no user account; its references point to the migrating user (NOT NULL columns) or NULL.
   User 'jason' (#27): the former program stored a plain-text password; it was hashed and the user must change it at the first login.
   User 'emy' (#28): the former program stored a plain-text password; it was hashed and the user must change it at the first login.
   User 'maria antonia' (#29): the former program stored a plain-text password; it was hashed and the user must change it at the first login.
-- page access (6)
   Role "Water: Loan Officer" has no page rights: none of its legacy assignments had page permissions. Give it pages on User Role Configuration.
   Role "Water: Bookkeeper" has no page rights: none of its legacy assignments had page permissions. Give it pages on User Role Configuration.
   Role "Water: Admin/Finance Manager" has no page rights: none of its legacy assignments had page permissions. Give it pages on User Role Configuration.
   Role "Water: Crecom Chairman" has no page rights: none of its legacy assignments had page permissions. Give it pages on User Role Configuration.
   Role "Water: Treasurer" has no page rights: none of its legacy assignments had page permissions. Give it pages on User Role Configuration.
   Role "Water: Teller 2" has no page rights: none of its legacy assignments had page permissions. Give it pages on User Role Configuration.
-- items (1)
   Bill code WATER METER -> item WATER METER (#29), opening quantity 0 (the former program held no stock for it); its legacy income, cost of goods sold, inventory asset account was not of the right kind, so the cooperative default was used. Review on the Item List.
-- bill codes (2)
   Bill code MEMBERSHIP FEE: its A/R account "Service Fee" is not an Accounts Receivable account (Income). Review on the Bill Codes page.
   Bill code WATER METER: its A/R account "A/D - Store & Office Equipment" is not an Accounts Receivable account (Fixed Asset). Review on the Bill Codes page.
-- water accounts (2)
   Account 41902 CINCO, EFREN 10 C/O SHERLY: no rate code in the former database; it cannot be billed until one is assigned on Water Service Accounts (the former program refused it too).
   Account 31856 OMBOY, MARK F Y 2 C/O MICHAEL NOURSE: no rate code in the former database; it cannot be billed until one is assigned on Water Service Accounts (the former program refused it too).
-- legacy membership (19)
   Member 100002 Abing, Silveria ..: legacy membership type 1 taken as an associate member (ASI). Confirm on Member Information.
   Member 100005 Agtag, Wilma S.: legacy membership status code 4 has no definition in the former database; brought in as applicant. Set the right status (active, resigned, terminated, deceased) on Member Information.
   Member 100017 Armilla, Ma. Sahlee ..: legacy membership status code 4 has no definition in the former database; brought in as applicant. Set the right status (active, resigned, terminated, deceased) on Member Information.
   Member 100044 Cahiyang, Maureen C.: legacy membership status code 4 has no definition in the former database; brought in as applicant. Set the right status (active, resigned, terminated, deceased) on Member Information.
   Member 100106 "MNHS, . ..": no first name in the former register; imported with "-" as first name. Correct it on Member Information.
   Member 100148 Suliano, Candelaria ..: legacy membership status code 4 has no definition in the former database; brought in as applicant. Set the right status (active, resigned, terminated, deceased) on Member Information.
   Member 100165 Ycong, Fe ..: legacy membership status code 4 has no definition in the former database; brought in as applicant. Set the right status (active, resigned, terminated, deceased) on Member Information.
   Member 100171 Ando, Cherry A.: legacy membership status code 4 has no definition in the former database; brought in as applicant. Set the right status (active, resigned, terminated, deceased) on Member Information.
   Member 100179 Tangoan, Luisa ..: legacy membership status code 4 has no definition in the former database; brought in as applicant. Set the right status (active, resigned, terminated, deceased) on Member Information.
   Member 100180 Dungog, Joan ..: legacy membership status code 4 has no definition in the former database; brought in as applicant. Set the right status (active, resigned, terminated, deceased) on Member Information.
   Member 100183 Badoy, Nancy ..: legacy membership status code 4 has no definition in the former database; brought in as applicant. Set the right status (active, resigned, terminated, deceased) on Member Information.
   Member 100188 Guanizon, Emmalinda M.: legacy membership status code 0 has no definition in the former database; brought in as applicant. Set the right status (active, resigned, terminated, deceased) on Member Information.
   Member 100194 Limpangug, Allana V.: legacy membership status code 0 has no definition in the former database; brought in as applicant. Set the right status (active, resigned, terminated, deceased) on Member Information.
   Member 100199 Dela Cruz, Sheryla Marie Rosento: legacy membership status code 2 has no definition in the former database; brought in as applicant. Set the right status (active, resigned, terminated, deceased) on Member Information.
   Member 100223 DINOPOL, SHEILA ESPINOSA: legacy membership status code 0 has no definition in the former database; brought in as applicant. Set the right status (active, resigned, terminated, deceased) on Member Information.
   Member 100319 OCHEA, VIA  MANIANITA: legacy membership status code 2 has no definition in the former database; brought in as applicant. Set the right status (active, resigned, terminated, deceased) on Member Information.
   Member 100327 YUMOL, CRISTINA MONTERO: legacy membership type 1 taken as an associate member (ASI). Confirm on Member Information.
   Member 100328 Mendez, Juvilyn Vivas: legacy membership status code 0 has no definition in the former database; brought in as applicant. Set the right status (active, resigned, terminated, deceased) on Member Information.
   NOT IMPORTED (the default): the former database carries a cooperative membership register of 361 member(s) and 335 savings account(s) holding 28,713,513.24 at 2026-10-14. They are kept read-only (membershipinfo, legacy_sav*) and written to the two CSV files above. Importing them creates cooperative members and posts the savings as opening balances: do it only when the cooperative database does not already hold these members and savings. Then run: php tools/migrate_water.php --source=mnssmc_src --phase=membership --import-membership --savings-product=<code> --cutover=2026-10-14
-- mapping check (6)
   Bill code MEMBERSHIP FEE: A/R account: 40128 Service Fee is a Income account, not Accounts Receivable (bills of this code cannot post)
   Bill code MEMBERSHIP FEE: income account: 11115 Cash on Hand - Collector 1 is a Bank account; a bill credits income or a deposit liability (bills of this code cannot post)
   Bill code WATER METER: A/R account: 14183 A/D - Store & Office Equipment is a Fixed Asset account, not Accounts Receivable (bills of this code cannot post)
   Bill code WATER METER: income account: 11118 Cash in Vault is a Bank account; a bill credits income or a deposit liability (bills of this code cannot post)
   Collection policy: penalty income: not mapped
   Collection policy: compromise income: not mapped
```
