<?php

namespace Tests\Feature\CustomerCommerce;

use App\Http\Requests\CustomerOrder\ShipCustomerOrderRequest;
use App\Http\Requests\CustomerOrder\UpdateCustomerOrderShipmentRequest;
use App\Models\CustomerAccount;
use App\Models\CustomerOrder;
use App\Models\User;
use App\Services\CustomerCommerce\StorefrontCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Customer commerce — celah kecil validasi masukan:
 *  - nomor resi memakai jangkar \A…\z (bukan ^…$ yang meloloskan baris baru akhir);
 *  - kata sandi pelanggan dibatasi 72 BYTE (bcrypt memotong di 72 byte), bukan hanya 72 karakter;
 *  - teks staf yang dibaca pelanggan menolak karakter arah baca dan lebar nol.
 */
class InputHardeningTest extends TestCase
{
    use CustomerCommerceFixture, RefreshDatabase;

    /** @var array<string,mixed> */
    private array $world;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableCustomerCommerce();
        $this->world = $this->buildStorefrontWorld();
        $this->staff = $this->fulfilmentStaff();
    }

    private function act(CustomerOrder $order, string $action, array $body = [])
    {
        return $this->asStaff($this->staff)->postJson("/api/v1/customer-orders/{$order->id}/{$action}", $body);
    }

    // ------------------------------------------------------------------
    // (1) Nomor resi: \A…\z
    // ------------------------------------------------------------------

    /**
     * Middleware TrimStrings membuang spasi/baris baru tepi pada permintaan HTTP sungguhan, jadi baris baru
     * akhir hanya bisa sampai ke aturan bila ada jalur yang melewatinya. Aturannya sendiri harus tetap
     * menolaknya (pertahanan berlapis), karena itulah yang diuji di sini: langsung pada aturan request.
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function trackingNumbers(): array
    {
        return [
            'resi biasa' => ['JNE-0001', true],
            'huruf dan angka saja' => ['A1B2C3', true],
            'baris baru di akhir' => ["JNE-0001\n", false],
            'baris baru di tengah' => ["JNE\n0001", false],
            'CRLF di akhir' => ["JNE-0001\r\n", false],
            'spasi' => ['JNE 0001', false],
            'tautan' => ['https://evil.example/x', false],
        ];
    }

    #[DataProvider('trackingNumbers')]
    public function test_the_ship_request_tracking_rule_is_anchored_at_both_ends(string $number, bool $valid): void
    {
        $rules = ['tracking_number' => (new ShipCustomerOrderRequest)->rules()['tracking_number']];

        $this->assertSame($valid, Validator::make(['tracking_number' => $number], $rules)->passes());
    }

    #[DataProvider('trackingNumbers')]
    public function test_the_shipment_update_request_tracking_rule_is_anchored_at_both_ends(string $number, bool $valid): void
    {
        $rules = ['tracking_number' => (new UpdateCustomerOrderShipmentRequest)->rules()['tracking_number']];

        $this->assertSame($valid, Validator::make(['tracking_number' => $number], $rules)->passes());
    }

    public function test_the_tracking_pattern_is_anchored_with_a_and_z(): void
    {
        // Menjaga bentuknya: `$` (tanpa D) cocok sebelum baris baru akhir, \z tidak.
        $this->assertSame(0, preg_match(ShipCustomerOrderRequest::TRACKING_NUMBER_PATTERN, "ABC\n"));
        $this->assertSame(1, preg_match('/^[A-Za-z0-9-]+$/', "ABC\n"), 'Pola lama memang meloloskannya.');
    }

    // ------------------------------------------------------------------
    // (2) Kata sandi: 72 byte
    // ------------------------------------------------------------------

    public function test_register_refuses_a_password_over_72_bytes_even_when_under_72_characters(): void
    {
        // 37 x "é" (2 byte) = 37 karakter, 74 byte.
        $response = $this->withHeader('Accept-Language', 'id')->postJson('/api/v1/customer/auth/register', [
            'name' => 'Rina', 'email' => 'rina@example.test', 'password' => str_repeat("\u{00E9}", 37),
        ]);

        $response->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->assertSame(
            ['Kata sandi paling banyak 72 byte (huruf beraksen dan non-Latin memakai lebih dari satu byte).'],
            $response->json('errors.password'),
        );
        $this->assertSame(0, CustomerAccount::count());
    }

    public function test_register_accepts_exactly_72_bytes(): void
    {
        $ascii = str_repeat('a', 72);
        $accented = str_repeat("\u{00E9}", 36); // 36 karakter, tepat 72 byte

        $this->postJson('/api/v1/customer/auth/register', ['name' => 'A', 'email' => 'a@example.test', 'password' => $ascii])->assertCreated();
        $this->postJson('/api/v1/customer/auth/register', ['name' => 'B', 'email' => 'b@example.test', 'password' => $accented])->assertCreated();
    }

    public function test_the_72_character_limit_still_applies_to_ascii(): void
    {
        $this->postJson('/api/v1/customer/auth/register', ['name' => 'A', 'email' => 'a@example.test', 'password' => str_repeat('a', 73)])
            ->assertStatus(422)->assertJsonValidationErrors(['password']);
    }

    public function test_login_keeps_answering_422_for_an_overlong_password_including_multibyte(): void
    {
        $this->makeCustomerAccount(['email' => 'rina@example.test']);

        foreach ([str_repeat('p', 73), str_repeat("\u{00E9}", 37), str_repeat("\u{20AC}", 25)] as $password) {
            $this->postJson('/api/v1/customer/auth/login', ['email' => 'rina@example.test', 'password' => $password])
                ->assertStatus(422)
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertJsonPath('code', 'validation_failed')
                ->assertJsonValidationErrors(['password']);
        }

        // Tepat 72 byte masih sampai ke pemeriksaan kredensial (sandi salah = 401 generik).
        $this->postJson('/api/v1/customer/auth/login', ['email' => 'rina@example.test', 'password' => str_repeat("\u{00E9}", 36)])
            ->assertStatus(401)->assertJsonPath('code', 'invalid_credentials');
    }

    // ------------------------------------------------------------------
    // (3) Teks staf yang dibaca pelanggan: tanpa karakter arah baca / lebar nol
    // ------------------------------------------------------------------

    /** @return array<string, array{0: string}> */
    public static function invisibleFormatCharacters(): array
    {
        return [
            'zero width space U+200B' => ["stok\u{200B}habis"],
            'zero width non-joiner U+200C' => ["stok\u{200C}habis"],
            'zero width joiner U+200D' => ["stok\u{200D}habis"],
            'left-to-right mark U+200E' => ["stok\u{200E}habis"],
            'right-to-left mark U+200F' => ["stok\u{200F}habis"],
            'left-to-right embedding U+202A' => ["stok\u{202A}habis"],
            'right-to-left override U+202E' => ["stok\u{202E}habis"],
            'left-to-right isolate U+2066' => ["stok\u{2066}habis"],
            'pop directional isolate U+2069' => ["stok\u{2069}habis"],
            'BOM U+FEFF' => ["stok\u{FEFF}habis"],
        ];
    }

    #[DataProvider('invisibleFormatCharacters')]
    public function test_a_staff_note_with_a_hidden_formatting_character_is_refused(string $text): void
    {
        $order = $this->makeOrderInStatus('awaiting_confirmation', [[$this->world['variantA'], 1]]);

        $this->act($order, 'confirm', ['note' => $text])->assertStatus(422)->assertJsonValidationErrors(['note']);

        $this->assertSame('awaiting_confirmation', $order->fresh()->status);
        $this->assertSame(0, DB::table('customer_order_status_events')->where('customer_order_id', $order->id)->count());
    }

    #[DataProvider('invisibleFormatCharacters')]
    public function test_a_cancel_reason_and_a_service_name_with_a_hidden_formatting_character_are_refused(string $text): void
    {
        $confirmed = $this->makeOrderInStatus('confirmed', [[$this->world['variantA'], 1]]);
        $this->act($confirmed, 'cancel', ['reason' => $text])->assertStatus(422)->assertJsonValidationErrors(['reason']);
        $this->assertSame('confirmed', $confirmed->fresh()->status);

        $packing = $this->makeOrderInStatus('packing', [[$this->world['variantB'], 1]], courier: true);
        $this->act($packing, 'ship', ['courier_name' => 'JNE', 'service_name' => $text])->assertStatus(422)->assertJsonValidationErrors(['service_name']);
        $this->assertSame('packing', $packing->fresh()->status);
    }

    public function test_ordinary_text_including_non_latin_scripts_and_emoji_is_still_accepted(): void
    {
        $order = $this->makeOrderInStatus('awaiting_confirmation', [[$this->world['variantA'], 1]]);

        // Di luar rentang yang ditolak: huruf beraksen, aksara Jepang, emoji tunggal, tanda hubung Unicode,
        // spasi sempit U+202F (tepat di luar U+202A–U+202E) dan U+2070 (tepat di luar U+2066–U+2069).
        $note = "Pesanan \u{00E9} diterima \u{2705} \u{65E5}\u{672C}\u{8A9E} \u{2010} 10\u{202F}pcs \u{2070}";

        $this->act($order, 'confirm', ['note' => $note])->assertOk();
        $this->assertSame($note, DB::table('customer_order_status_events')->where('customer_order_id', $order->id)->sole()->note);
    }

    // ------------------------------------------------------------------
    // (4) Daftar pesanan staf: nomor halaman dibatasi
    // ------------------------------------------------------------------

    public function test_the_staff_order_list_caps_the_page_number_like_the_customer_list(): void
    {
        $this->makeOrderInStatus('awaiting_confirmation', [[$this->world['variantA'], 1]]);
        $max = StorefrontCatalogService::MAX_PAGE;

        // Tanpa batas, halaman sebesar ini membuat OFFSET melampaui bilangan bulat dan basis data menjawab 500.
        foreach ([(string) ($max + 1), '9223372036854775807'] as $page) {
            $this->asStaff($this->staff)->getJson("/api/v1/customer-orders?page={$page}&per_page=100")
                ->assertStatus(422)->assertJsonValidationErrors(['page']);
        }

        // Tepat di batas masih sah: daftar kosong, bukan galat.
        $this->asStaff($this->staff)->getJson("/api/v1/customer-orders?page={$max}&per_page=100")
            ->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.current_page', $max);
    }
}
