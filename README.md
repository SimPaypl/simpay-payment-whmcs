# SimPay – płatności online dla WHMCS

Moduł **SimPay** umożliwia realizację płatności online w systemach opartych o **WHMCS 8.x+**.  
Integracja jest w pełni osadzona w ścieżce płatności faktur, a dodatkowo zapewnia podgląd statusu transakcji bezpośrednio z panelu administracyjnego.

---

## Spis treści

- [Cechy](#cechy)
- [Wymagania](#wymagania)
- [Instalacja](#instalacja)
- [Aktualizacja](#aktualizacja)
- [Konfiguracja](#konfiguracja)
    - [Dane uwierzytelniające](#dane-uwierzytelniające)
    - [Adres komunikacji (webhook / IPN)](#adres-komunikacji-webhook--ipn)
    - [Kanały płatności](#kanały-płatności)
    - [Walidacja IP](#walidacja-ip)
- [Panel admina – status transakcji](#panel-admina--status-transakcji)
    - [Podgląd statusu transakcji](#podgląd-statusu-transakcji)
- [Logi i diagnostyka](#logi-i-diagnostyka)
- [Znane problemy](#znane-problemy)
- [Development](#development)
- [Wsparcie](#wsparcie)

---

## Cechy

Moduł dodaje do WHMCS obsługę płatności SimPay oraz umożliwia m.in.:

- prezentację dostępnych **kanałów płatności** (banki, BLIK, karty) bezpośrednio na stronie faktury,
- klient wybiera metodę płatności **przed przekierowaniem** do bramki,
- automatyczny fallback do przekierowania bez wyboru kanału w razie problemów z API,
- **podgląd statusu transakcji** w szczegółach płatności w panelu admina,
- obsługę wielu typów webhooków IPN: płatność, wygaśnięcie, anulowanie, refund, BLIK alias,
- bezpieczną walidację podpisu SHA-256 i listy dozwolonych adresów IP,
- mechanizm sprawdzania dostępności aktualizacji modułu (powiadomienie w konfiguracji + link do pobrania),
- wsparcie dla **directChannel** — klient trafia bezpośrednio do wybranego banku/BLIK bez dodatkowych kroków.

---

## Wymagania

- WHMCS: **8.0** lub nowszy
- PHP: **8.1** lub nowszy
- Konto SimPay z aktywną usługą płatności online
- Dostęp do panelu SimPay w celu pobrania danych integracyjnych

> ⚠️ **Uwaga**  
> Moduł wymaga PHP 8.1+ ze względu na wykorzystanie oficjalnego SDK `simpay/ecommerce`

---

## Instalacja

1. Pobierz najnowszą paczkę `simpay-whmcs-vX.Y.Z.zip` z sekcji [**Releases**](https://github.com/SimPaypl/simpay-whmcs/releases).
2. Rozpakuj archiwum — wewnątrz znajdziesz katalog `modules/`.
3. Skopiuj (lub wgraj przez FTP) zawartość do głównego katalogu WHMCS, zachowując strukturę:
   ```
   whmcs/
   └── modules/gateways/
       ├── simpaypayment.php
       ├── callback/simpaypayment.php
       └── simpaypayment/
           ├── vendor/
           ├── whmcs.json
           └── logo.png
   ```
4. Zaloguj się do panelu admina WHMCS.
5. Przejdź do: **Ustawienia → Bramki płatności → Wszystkie bramki płatności**.
6. Znajdź **SimPay.pl Przelewy i BLIK** i kliknij **Aktywuj**.
7. Uzupełnij dane konfiguracyjne (Bearer Token, ID usługi, Klucz sygnatury).
8. Ustaw adres URL IPN w panelu SimPay (wyświetlony w konfiguracji modułu).

---

## Aktualizacja

1. Pobierz nową paczkę `.zip` z sekcji **Releases**.
2. Nadpisz pliki modułu w katalogu WHMCS (ta sama procedura co instalacja).
3. Wyczyść cache WHMCS jeśli jest włączony: **Ustawienia → Ogólne → Wyczyść cache szablonów**.

> Moduł informuje o dostępności nowej wersji bezpośrednio w konfiguracji bramki płatności.  
> Jeśli endpoint aktualizacji jest niedostępny, powiadomienie nie będzie wyświetlane.

---

## Konfiguracja

### Dane uwierzytelniające

W konfiguracji modułu uzupełnij dane otrzymane z panelu SimPay:

| Pole | Skąd pobrać |
|------|-------------|
| **Bearer Token API** | Panel SimPay → Konto → API → Szczegóły klucza |
| **ID usługi** | Panel SimPay → Płatności online → Usługi → Szczegóły |
| **Klucz sygnatury IPN** | Panel SimPay → Płatności online → Usługi → Ustawienia usługi |

### Adres komunikacji (webhook / IPN)

W panelu SimPay (Ustawienia usługi) dodaj adres URL IPN:

```
https://twoja-domena.pl/modules/gateways/callback/simpaypayment.php
```

> ⚠️ Upewnij się, że:
> - sklep działa po **HTTPS**,
> - adres URL jest **publicznie dostępny** (bez basic auth, firewall, itp.),
> - jeśli środowisko DEV jest zabezpieczone — użyj tunelu (np. Cloudflare Tunnel / ngrok).

### Kanały płatności

Moduł może wyświetlać listę dostępnych kanałów płatności (banki, BLIK, karty) bezpośrednio na stronie faktury.

Aby włączyć tę funkcję, zaznacz opcję **Wyświetlaj kanały płatności** w konfiguracji modułu.

Gdy opcja jest włączona:
- klient widzi siatkę ikon z dostępnymi metodami płatności,
- po kliknięciu wybranej metody i przycisku „Zapłać teraz" — trafia bezpośrednio do wybranego kanału,
- jeśli pobieranie kanałów z API nie powiedzie się — moduł automatycznie wyświetli standardowy przycisk z przekierowaniem.

Gdy opcja jest wyłączona:
- klient widzi przycisk „Zapłać teraz" i wybiera metodę już na stronie SimPay.

### Walidacja IP

Opcja **Sprawdzaj adres IP w IPN** weryfikuje, czy przychodzące powiadomienie pochodzi z serwerów SimPay.

- **Włączona** (domyślnie) — moduł pobiera listę dozwolonych IP z API SimPay i porównuje z adresem nadawcy.
- **Wyłączona** — zalecane jeśli WHMCS jest za reverse proxy (np. Cloudflare, nginx) i nagłówek `X-Forwarded-For` nie jest poprawnie przekazywany.

---

## Panel admina – status transakcji

### Podgląd statusu transakcji

W widoku faktury w panelu administracyjnym, przy płatności zrealizowanej przez SimPay, dostępny jest przycisk **Sprawdź status**.

Po kliknięciu moduł odpytuje API SimPay i wyświetla:

| Pole | Opis |
|------|------|
| Transaction ID | Unikalny identyfikator transakcji w SimPay |
| Status | Aktualny status (np. `transaction_paid`, `transaction_expired`) |
| Amount | Kwota i waluta transakcji |
| Channel | Kanał płatności użyty przez klienta |
| Paid At | Data i godzina opłacenia |
| Created At | Data utworzenia transakcji |


---

## Logi i diagnostyka

Moduł loguje wszystkie istotne zdarzenia w systemie logów WHMCS:

- **Ustawienia → Logi → Gateway Log** — pełna historia komunikacji z API SimPay.

Logowane zdarzenia obejmują:
- tworzenie transakcji (sukces i błędy),
- przychodzące powiadomienia IPN (wszystkie typy),
- błędy walidacji (nieprawidłowy podpis, nieznane IP, brakujące pola),
- operacje zwrotów,
- sprawdzanie statusu transakcji.

Każdy wpis zawiera:
- nazwę akcji (np. `link:api_error`, `ipn:validation_failed`, `refund:error`),
- dane wejściowe (payload),
- odpowiedź API lub komunikat błędu.

---

## Znane problemy

- **Reverse proxy / Cloudflare** — jeśli WHMCS jest za proxy, nagłówek `REMOTE_ADDR` może zawierać IP proxy zamiast IP SimPay. W takim przypadku wyłącz walidację IP w konfiguracji modułu lub skonfiguruj trusted proxies na serwerze.
- **Waluta inna niż PLN** — SimPay obsługuje wyłącznie PLN. Faktury w innej walucie wyświetlą komunikat o braku obsługi.
- **Basic auth na środowisku testowym** — webhooki IPN nie dotrą jeśli endpoint jest chroniony hasłem HTTP.

---

## Development

```bash
# Klonowanie repozytorium
git clone https://github.com/SimPaypl/simpay-whmcs.git
cd simpay-whmcs

# Instalacja zależności
composer install
```

### Budowanie paczki release

Paczka ZIP jest automatycznie budowana przez GitHub Actions przy tagowaniu:

```bash
git tag v2.0.0
git push origin v2.0.0
```

Workflow `.github/workflows/release.yml`:
1. Instaluje zależności Composer (`--no-dev --optimize-autoloader`)
2. Kopiuje pliki modułu do struktury release
3. Dołącza `vendor/` z autoloaderem i SDK `simpay/ecommerce`
4. Usuwa zbędne pliki (testy, `.github`, itp.)
5. Tworzy ZIP i publikuje jako GitHub Release

### Struktura projektu

```
├── composer.json                          # Zależność: simpay/ecommerce SDK
├── .github/workflows/release.yml          # CI: budowanie paczki ZIP
├── modules/gateways/
│   ├── simpaypayment.php                  # Główny plik gateway
│   ├── callback/simpaypayment.php         # IPN handler + channel redirect
│   └── simpaypayment/
│       ├── whmcs.json                     # Metadata modułu WHMCS
│       ├── logo.png                       # Logo wyświetlane w panelu
│       └── vendor/                        # (generowany przez CI, nie w repo)
```

---

## Wsparcie

Masz pytania lub chcesz zgłosić błąd?

- Utwórz zgłoszenie w zakładce [**Issues**](https://github.com/SimPaypl/simpay-whmcs/issues) w tym repozytorium (zalecane).
- Dołącz:
    - wersję WHMCS,
    - wersję PHP,
    - wersję modułu (widoczna w konfiguracji bramki),
    - fragment logów z **Gateway Log** (bez danych wrażliwych!),
    - kroki odtworzenia problemu.