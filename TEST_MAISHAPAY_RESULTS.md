# Tests MaishaPay Sandbox - Merchant 000945

Date: 2026-08-21
Keys:
- Public: MP-SBPK-.UCBEROe0e1ycKqKo1$rj/iSCcdBaeZ0Fbx38fkPUGokH/F$kSXfbES$.SOl32Evud21YiMulKAc./GJhp4P0i/BzF2X2VP$k2wq$yY9byj30V.$9re1fOgo
- Secret: MP-SBSK-OA82.3h5WVFaBQEa$PyHjlk8HEc$lNJhy2w4gne.eNo.Hsx$jXCaUy/1c4qjYM$KGtc.fjb$6Baku2Sh.SWHLJO$qVxNBK0yU1mcvFE7mO1gtVEPpDXom0.X
- Merchant ID: 000945
- Mode: 0 (Sandbox)

## Endpoint REST
`https://marchand.maishapay.online/api/payment/rest/vers1.0/merchant`

### Test Mobile Money
Payload:
```json
{
  "gatewayMode": 0,
  "publicApiKey": "MP-SBPK-...",
  "secretApiKey": "MP-SBSK-...",
  "transactionReference": "LME-20260821182046-6I1Z2V",
  "amount": 1.00,
  "currency": "USD",
  "customerFullName": "Test User",
  "customerPhoneNumber": "+243812345678",
  "customerEmailAddress": "test@example.com",
  "chanel": "MOBILEMONEY",
  "provider": "MPESA",
  "walletID": "+243812345678"
}
```
Response HTTP 200:
```json
{
  "status": 200,
  "data": {
    "originatingTransactionId": "LME-20260821182046-6I1Z2V",
    "statusCode": 200,
    "statusDescription": "Accepted",
    "transactionId": 264426,
    "paymentPage": null,
    "transactionDescription": "General Payments",
    "transactionDate": "21/08/2026 18:20:47"
  }
}
```
✅ Accepté - transactionId 264426, 264431

### Test Carte - chanel invalides
- `BANKCARD`, `CREDITCARD`, `VISA`, `MASTERCARD` => HTTP 400
```json
{"type":"error","title":"wrong payment channel","description":"Mode de paiement incorrect, veuillez choisir entre \"MOBILEMONEY\" et \"CARD\" "}
```
✅ Le seul chanel valide pour carte est `CARD`

### Test Carte - chanel=CARD sans callbackUrl
HTTP 400:
```json
{"title":"Validation error","errors":{"callbackUrl":["paramètre obligatoire pour les transactions via CARTE. Veuillez inserer l'Url de rappel"]}}
```
✅ callbackUrl obligatoire pour CARD

### Test Carte - chanel=CARD avec callbackUrl
Payload:
```json
{
  "gatewayMode": 0,
  "publicApiKey": "MP-SBPK-...",
  "secretApiKey": "MP-SBSK-...",
  "transactionReference": "CARDTEST-20260821182122-R30YPW",
  "amount": 1,
  "currency": "USD",
  "customerFullName": "Test User",
  "customerPhoneNumber": "+243812345678",
  "customerEmailAddress": "test@example.com",
  "chanel": "CARD",
  "provider": "VISA",
  "walletID": "+243812345678",
  "callbackUrl": "https://lme-group.zaloriatech.com/vote1_callback.php"
}
```
Response HTTP 200:
```json
{
  "status": 200,
  "data": {
    "originatingTransactionId": "CARDTEST-20260821182122-R30YPW",
    "statusCode": 200,
    "statusDescription": "Accepted",
    "transactionId": 264427,
    "paymentPage": null,
    "transactionDescription": "General Payments",
    "transactionDate": "21/08/2026 18:21:22"
  }
}
```
✅ Accepté pour VISA et MASTERCARD:
- VISA: transactionId 264427, 264429
- MASTERCARD: transactionId 264428, 264430
- Provider testés: VISA, MASTERCARD fonctionnent

### Test Checkout (hosted page pour carte)
Endpoint: `https://marchand.maishapay.online/payment/vers1.0/merchant/checkout`
Payload form-data:
```
gatewayMode=0
publicApiKey=MP-SBPK-...
secretApiKey=MP-SBSK-...
montant=5
devise=USD
callbackUrl=https://lme-group.zaloriatech.com/vote1_callback.php?ref=TEST123
```
Response HTTP 200 HTML:
- Title: Payment Panel | MaishaPay
- Contient: sélecteur pays (RDC, Congo, etc), Montant à payer 5 USD, bouton Refuser paiement
- Contient keywords "card" et "visa"
✅ Checkout fonctionne, retourne page hébergée MaishaPay où user saisit carte (CyberSource 3D Secure)

### Test Duplicate Reference
Si on réutilise même transactionReference:
HTTP 400:
```json
{"type":"error","title":"Duplicate value","description":"Le numéro de référence transmis existe déjà dans vos transactions, veuillez transmettre une référence qui soit unique"}
```
✅ Référence doit être unique

### Conclusion intégration vote1 - Respect demande user
- **Mobile Money = Unipesa/Avadapay uniquement** (comme voter.php original):
  - `vote1_api.php?action=initiate_payment` utilise Unipesa `payment_c2b` avec signature HMAC, provider_id 9/10/17/19, callback vers vote1_api.php
  - Pas de Maishapay pour mobile (même si testé OK, on garde séparation demandée)
- **Carte Visa/Mastercard = Maishapay uniquement**:
  - `vote1_api.php?action=initiate_card_payment` utilise REST `chanel=CARD` + provider `VISA/MASTERCARD` + callbackUrl obligatoire → testé Accepted 264427-264430
  - Ensuite Checkout form POST vers `https://marchand.maishapay.online/payment/vers1.0/merchant/checkout` (page hébergée CyberSource 3D Secure)
  - Merchant ID 000945 Sandbox
- Status:
  - Mobile: polling Unipesa status endpoint comme voter_api.php + callback Unipesa
  - Carte: pas d'endpoint status public Maishapay, on se base sur callbackUrl GET `status, description, transactionRefId, operatorRefId` + polling DB local + vote1_callback.php
- Logs: unipesa.log (mobile), maishapay.log (carte), maishapay_callback.log, maishapay_callback_raw.log

Fichiers créés:
- vote1.php (clone voter.php + UI Mobile via Unipesa + Carte via Maishapay)
- vote1_api.php (Unipesa pour mobile + Maishapay REST + Checkout pour carte)
- vote1_callback.php (callback Maishapay carte GET/POST + update DB + redirect reçu)
