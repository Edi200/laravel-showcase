<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { useForm } from '@inertiajs/vue3';
import { Banknote, Minus, Plus, X } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import CustomerLayout from '@/layouts/CustomerLayout.vue';
import { home } from '@/routes';
import { isDecimalUnit, roundCartQuantity, useCartStore } from '@/stores/cartStore';
import type { CartItem } from '@/stores/cartStore';
import { formatPrice } from '@/utils/formatPrice';
import { formatQuantity } from '@/utils/formatQuantity';

type AddressOption = {
    id: number;
    label: string | null;
    street: string;
    house_number: string;
    city: string;
    is_default: boolean;
};

type CityOption = {
    id: number;
    name_sr: string;
    name_hu: string | null;
};

type CheckoutStore = {
    id: number;
    name: string;
    logo_url: string | null;
};

const DEFAULT_CITY_STORED = 'Bačka Topola';

defineOptions({
    layout: CustomerLayout,
});

const props = defineProps<{
    addresses: AddressOption[];
    cities: CityOption[];
    hasPhone: boolean;
    store: CheckoutStore | null;
}>();

const { locale } = useI18n();
const cart = useCartStore();

const selectedAddressId = ref<number | 'new' | null>(
    props.addresses.length > 0
        ? (props.addresses.find((a) => a.is_default)?.id ?? props.addresses[0]?.id) ?? 'new'
        : 'new',
);
const street = ref('');
const house_number = ref('');
const selectedCityId = ref<number | null>(props.cities[0]?.id ?? null);
const saveAddress = ref(false);

const csrfToken =
    (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content ?? '';

const form = useForm<{
    store_id: number;
    items: { product_id: number | string; quantity: number }[];
    city: string;
    delivery_address: string;
    notes: string;
}>({
    store_id: 0,
    items: [],
    city: '',
    delivery_address: '',
    notes: '',
});

const selectedAddress = computed(() =>
    typeof selectedAddressId.value === 'number'
        ? props.addresses.find((a) => a.id === selectedAddressId.value) ?? null
        : null,
);

const selectedCity = computed(() =>
    selectedCityId.value === null
        ? null
        : props.cities.find((city) => city.id === selectedCityId.value) ?? null,
);

function getLocalized(nameSr: string, nameHu: string | null): string {
    return locale.value === 'hu' && nameHu ? nameHu : nameSr;
}

const cityForInput = computed(() => {
    if (selectedAddress.value) {
        return selectedAddress.value.city;
    }

    if (props.cities.length === 1) {
        return getLocalized(props.cities[0].name_sr, props.cities[0].name_hu);
    }

    if (props.cities.length >= 2) {
        return selectedCity.value
            ? getLocalized(selectedCity.value.name_sr, selectedCity.value.name_hu)
            : '';
    }

    return DEFAULT_CITY_STORED;
});

watch(
    [selectedAddressId, selectedAddress],
    () => {
        const addr = selectedAddress.value;
        if (addr) {
            street.value = addr.street;
            house_number.value = addr.house_number;
        } else if (selectedAddressId.value === 'new' && props.addresses.length > 0) {
            street.value = '';
            house_number.value = '';
        }

        if (!addr && selectedAddressId.value === 'new' && props.cities.length > 0 && selectedCityId.value === null) {
            selectedCityId.value = props.cities[0].id;
        }
    },
    { immediate: true },
);

const deliveryAddressString = computed(() => {
    const s = street.value.trim();
    const h = house_number.value.trim();
    const city = cityForInput.value.trim();
    if (!s || !h) return '';
    if (!city) return '';
    return `${s} ${h}, ${city}`;
});

const canSubmit = computed(
    () =>
        props.hasPhone &&
        cart.items.length > 0 &&
        cart.storeId != null &&
        deliveryAddressString.value.length > 0,
);

const freeDeliveryMissingAmount = computed<number | null>(() => {
    const above = cart.freeDeliveryAboveAmount;
    if (above == null || above <= 0) return null;
    const sub = cart.subtotal;
    if (sub >= above) return null;
    return Math.round((above - sub) * 100) / 100;
});

const showManualFields = computed(
    () => props.addresses.length === 0 || selectedAddressId.value === 'new',
);

async function submitOrder() {
    if (!canSubmit.value) return;
    if (saveAddress.value && showManualFields.value) {
        try {
            await fetch('/profile/addresses', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}),
                },
                body: JSON.stringify({
                    street: street.value.trim(),
                    house_number: house_number.value.trim(),
                    city: cityForInput.value,
                }),
            });
        } catch {
            // Continue to place order even if save fails
        }
    }
    form.store_id = cart.storeId as number;
    form.items = cart.items.map((item) => ({
        product_id: item.productId,
        quantity: item.quantity,
    }));
    form.city = cityForInput.value;
    form.delivery_address = deliveryAddressString.value;
    form.post('/orders', {
        preserveScroll: true,
        onSuccess: () => {
            cart.clearCart();
        },
    });
}

const quantityStep = (item: CartItem): number => (isDecimalUnit(item.unit) ? 0.1 : 1);

const minQuantityBeforeRemove = (item: CartItem): number =>
    isDecimalUnit(item.unit) ? 0.1 : 1;

function decrementQuantity(item: CartItem) {
    const step = quantityStep(item);
    const q = roundCartQuantity(item.quantity);
    if (q > minQuantityBeforeRemove(item) + 1e-9) {
        cart.updateQuantity(item.productId, roundCartQuantity(q - step));
    } else {
        cart.removeItem(item.productId);
    }
}

function incrementQuantity(item: CartItem) {
    const step = quantityStep(item);
    const q = roundCartQuantity(item.quantity);
    const next = roundCartQuantity(q + step);
    if (item.maxQuantity != null) {
        const cap = roundCartQuantity(Number(item.maxQuantity));
        if (next > cap + 1e-9) {
            return;
        }
    }
    cart.updateQuantity(item.productId, next);
}

function isIncrementDisabled(item: CartItem): boolean {
    if (item.maxQuantity == null) {
        return false;
    }

    const step = quantityStep(item);
    const q = roundCartQuantity(item.quantity);
    const cap = roundCartQuantity(Number(item.maxQuantity));

    return roundCartQuantity(q + step) > cap + 1e-9;
}
</script>

<template>
    <Head :title="$t('layout.checkout')" />

    <div class="mx-auto w-full max-w-2xl px-4 py-6">
        <h1 class="mb-6 text-2xl font-semibold tracking-tight">
            {{ $t('layout.checkout') }}
        </h1>

        <div v-if="cart.items.length === 0" class="rounded-xl border bg-card p-8 text-center">
            <p class="mb-4 text-muted-foreground">
                {{ $t('layout.cartEmpty') }}
            </p>
            <Link :href="home()">
                <Button variant="outline">
                    {{ $t('layout.backToStores') }}
                </Button>
            </Link>
        </div>

        <template v-else>
            <div class="space-y-6">
                <section class="rounded-xl border bg-card p-4">
                    <div class="mb-3 flex items-center gap-3">
                        <div class="h-12 w-12 shrink-0 overflow-hidden rounded-lg bg-muted">
                            <img
                                v-if="store?.logo_url"
                                :src="store.logo_url"
                                :alt="cart.storeName ?? $t('layout.store')"
                                class="h-full w-full object-cover"
                            />
                            <div
                                v-else
                                class="flex h-full w-full items-center justify-center text-xs text-muted-foreground"
                            >
                                —
                            </div>
                        </div>
                        <h2 class="text-lg font-medium">
                            {{ cart.storeName ?? $t('layout.store') }}
                        </h2>
                    </div>

                    <!-- Table header (desktop) -->
                    <div class="hidden grid-cols-12 gap-2 border-b pb-2 text-xs font-medium text-muted-foreground sm:grid">
                        <div class="col-span-1" />
                        <div class="col-span-5">{{ $t('layout.product') }}</div>
                        <div class="col-span-2 text-right">{{ $t('layout.unitPrice') }}</div>
                        <div class="col-span-2 text-center">{{ $t('layout.quantity') }}</div>
                        <div class="col-span-2 text-right">{{ $t('layout.total') }}</div>
                    </div>

                    <!-- Cart rows -->
                    <div
                        v-for="item in cart.items"
                        :key="item.productId"
                        class="flex flex-col gap-2 border-b py-3 text-sm last:border-b-0 sm:grid sm:grid-cols-12 sm:items-center sm:gap-2"
                    >
                        <div class="flex items-center gap-3 sm:col-span-6">
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                class="h-8 w-8 shrink-0 text-muted-foreground hover:text-destructive"
                                @click="cart.removeItem(item.productId)"
                            >
                                <X class="h-4 w-4" />
                            </Button>
                            <div
                                class="h-12 w-12 shrink-0 overflow-hidden rounded-lg bg-muted"
                            >
                                <img
                                    v-if="item.imageUrl"
                                    :src="item.imageUrl"
                                    :alt="item.name"
                                    class="h-full w-full object-cover"
                                />
                                <div
                                    v-else
                                    class="flex h-full w-full items-center justify-center text-xs text-muted-foreground"
                                >
                                    —
                                </div>
                            </div>
                            <span class="min-w-0 flex-1 font-medium">{{ item.name }}</span>
                        </div>
                        <div class="flex items-center justify-between gap-4 sm:col-span-6 sm:grid sm:grid-cols-6">
                            <span class="text-muted-foreground sm:col-span-2 sm:text-right">{{ formatPrice(item.price) }}</span>
                            <div class="flex items-center justify-center gap-1 sm:col-span-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="icon"
                                    class="h-8 w-8 shrink-0"
                                    @click="decrementQuantity(item)"
                                >
                                    <Minus class="h-3.5 w-3.5" />
                                </Button>
                                <span class="min-w-8 text-center font-medium tabular-nums">{{
                                    formatQuantity(Number(item.quantity), item.unit ?? null)
                                }}</span>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="icon"
                                    class="h-8 w-8 shrink-0"
                                    :disabled="isIncrementDisabled(item)"
                                    @click="incrementQuantity(item)"
                                >
                                    <Plus class="h-3.5 w-3.5" />
                                </Button>
                            </div>
                            <span class="font-medium sm:col-span-2 sm:text-right">{{ formatPrice(item.quantity * item.price) }}</span>
                        </div>
                    </div>

                    <p
                        v-if="freeDeliveryMissingAmount !== null"
                        class="mt-3 text-sm text-amber-600 dark:text-amber-400"
                    >
                        {{ $t('checkout.freeDeliveryMissing', { amount: freeDeliveryMissingAmount.toFixed(2) }) }}
                    </p>

                    <div class="mt-3 flex justify-between border-t pt-3 text-sm">
                        <span class="text-muted-foreground">
                            {{ $t('layout.subtotal') }}
                        </span>
                        <span>{{ formatPrice(cart.subtotal) }}</span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-muted-foreground">
                            {{ $t('layout.delivery') }}
                        </span>
                        <span>{{ formatPrice(cart.deliveryFee) }}</span>
                    </div>
                    <div class="flex justify-between border-t pt-3 font-semibold">
                        <span>{{ $t('layout.total') }}</span>
                        <span>{{ formatPrice(cart.total) }}</span>
                    </div>
                </section>

                <form class="space-y-4 rounded-xl border bg-card p-4" @submit.prevent="submitOrder">
                    <div v-if="addresses.length > 0" class="grid gap-2">
                        <Label for="address_select">{{ $t('checkout.selectAddress') }}</Label>
                        <select
                            id="address_select"
                            v-model="selectedAddressId"
                            class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm text-foreground shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring [&>option]:bg-background [&>option]:text-foreground [&>option]:text-base"
                        >
                            <option
                                v-for="addr in addresses"
                                :key="addr.id"
                                :value="addr.id"
                            >
                                {{ addr.label ? `${addr.label} — ` : '' }}{{ addr.street }} {{ addr.house_number }}, {{ addr.city }}
                            </option>
                            <option value="new">
                                {{ $t('checkout.newAddress') }}
                            </option>
                        </select>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="street">{{ $t('profile.street') }} *</Label>
                            <Input
                                id="street"
                                v-model="street"
                                type="text"
                                :readonly="!showManualFields"
                                class="w-full"
                                :class="{ 'border-red-500': form.errors.delivery_address, 'bg-muted': !showManualFields }"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="house_number">{{ $t('profile.houseNumber') }} *</Label>
                            <Input
                                id="house_number"
                                v-model="house_number"
                                type="text"
                                :readonly="!showManualFields"
                                class="w-full"
                                :class="{ 'border-red-500': form.errors.delivery_address, 'bg-muted': !showManualFields }"
                            />
                        </div>
                    </div>
                    <div class="grid gap-2">
                        <Label for="city">{{ $t('profile.city') }}</Label>
                        <select
                            v-if="cities.length >= 2 && showManualFields"
                            id="city"
                            v-model.number="selectedCityId"
                            class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm text-foreground shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring [&>option]:bg-background [&>option]:text-foreground [&>option]:text-base"
                            :class="{ 'border-red-500': form.errors.city }"
                        >
                            <option v-for="city in cities" :key="city.id" :value="city.id">
                                {{ getLocalized(city.name_sr, city.name_hu) }}
                            </option>
                        </select>
                        <Input
                            v-else
                            id="city"
                            :model-value="cityForInput"
                            type="text"
                            readonly
                            class="w-full bg-muted"
                            :class="{ 'border-red-500': form.errors.city }"
                        />
                        <InputError :message="form.errors.city" />
                    </div>
                    <div class="flex items-start gap-3 rounded-lg bg-muted px-3 py-2 text-xs text-muted-foreground">
                        <div class="mt-0.5 flex h-7 w-7 items-center justify-center rounded-full bg-background/80">
                            <Banknote class="h-4 w-4 text-emerald-600" />
                        </div>
                        <div class="space-y-0.5">
                            <p class="font-medium text-foreground">
                                {{ $t('checkout.paymentMethod') }}
                            </p>
                            <p>
                                {{ $t('checkout.paymentMethodDescription') }}
                            </p>
                        </div>
                    </div>
                    <div v-if="showManualFields" class="flex items-center gap-2">
                        <input
                            id="save_address"
                            v-model="saveAddress"
                            type="checkbox"
                            class="h-4 w-4 rounded border-input"
                        />
                        <Label for="save_address" class="cursor-pointer text-sm font-normal">
                            {{ $t('profile.saveAddress') }}
                        </Label>
                    </div>
                    <InputError :message="form.errors.delivery_address" />
                    <div class="grid gap-2">
                        <Label for="notes">
                            {{ $t('layout.notes') }}
                            ({{ $t('layout.optional') }})
                        </Label>
                        <Input
                            id="notes"
                            v-model="form.notes"
                            type="text"
                            class="w-full"
                            :class="{ 'border-red-500': form.errors.notes }"
                        />
                        <InputError :message="form.errors.notes" />
                    </div>
                    <Button
                        type="submit"
                        class="w-full"
                        :disabled="!canSubmit || form.processing || !hasPhone"
                    >
                        {{ form.processing ? '...' : $t('layout.placeOrder') }}
                    </Button>
                </form>
            </div>
        </template>
    </div>
</template>
