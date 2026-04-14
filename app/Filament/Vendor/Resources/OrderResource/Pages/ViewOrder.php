<?php

namespace App\Filament\Vendor\Resources\OrderResource\Pages;

use App\Events\DeliveryTaskAvailable;
use App\Events\OrderStatusUpdated;
use App\Filament\Vendor\Resources\OrderResource;
use App\Services\OrderStockService;
use Filament\Actions\Action as FormAction;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    public function getTitle(): string
    {
        return 'Pregled narudžbine';
    }

    protected function getHeaderActions(): array
    {
        return [
            FormAction::make('confirm')
                ->label('Potvrdi')
                ->visible(fn (Model $record): bool => $record->status === 'pending')
                ->form([
                    TextInput::make('estimated_prep_minutes')
                        ->label('Procena vremena pripreme (min)')
                        ->extraInputAttributes([
                            'style' => 'text-align: center; font-size: 1.5rem; font-weight: 600;',
                        ])
                        ->prefixAction(
                            FormAction::make('minus')
                                ->icon('heroicon-o-minus')
                                ->action(function (Set $set, Get $get): void {
                                    $current = (int) ($get('estimated_prep_minutes') ?? 20);

                                    $set('estimated_prep_minutes', max(5, $current - 5));
                                }),
                        )
                        ->suffixAction(
                            FormAction::make('plus')
                                ->icon('heroicon-o-plus')
                                ->action(function (Set $set, Get $get): void {
                                    $current = (int) ($get('estimated_prep_minutes') ?? 20);

                                    $set('estimated_prep_minutes', min(60, $current + 5));
                                }),
                        )
                        ->numeric()
                        ->default(20)
                        ->minValue(5)
                        ->maxValue(60)
                        ->step(5)
                        ->required(),
                ])
                ->action(function (Model $record, array $data): void {
                    $record->estimated_prep_minutes = $data['estimated_prep_minutes'] ?? null;
                    $record->confirmed_at = now();
                    $record->status = 'confirmed';

                    app(OrderStockService::class)->decreaseStockForOrder($record);

                    $record->save();

                    event(new OrderStatusUpdated($record));

                    $record->loadMissing('deliveryTask');

                    if ($record->deliveryTask !== null) {
                        event(new DeliveryTaskAvailable($record->deliveryTask));
                    }
                }),
            FormAction::make('preparing')
                ->label('U pripremi')
                ->visible(fn (Model $record): bool => $record->status === 'confirmed')
                ->action(function (Model $record): void {
                    $record->status = 'preparing';
                    $record->save();

                    event(new OrderStatusUpdated($record));
                }),
            FormAction::make('ready')
                ->label('Spremno')
                ->visible(fn (Model $record): bool => $record->status === 'preparing')
                ->action(function (Model $record): void {
                    $record->status = 'ready';
                    $record->ready_at = now();
                    $record->save();

                    event(new OrderStatusUpdated($record));
                }),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Pregled narudžbine')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                TextEntry::make('id')
                                    ->label('Narudžbina'),
                                TextEntry::make('status')
                                    ->label('Status')
                                    ->badge()
                                    ->formatStateUsing(fn (string $state): string => match ($state) {
                                        'pending' => 'Na čekanju',
                                        'confirmed' => 'Potvrđeno',
                                        'preparing' => 'U pripremi',
                                        'ready' => 'Spremno',
                                        'picked_up' => 'Preuzeto',
                                        'delivered' => 'Dostavljeno',
                                        'completed' => 'Završeno',
                                        'cancelled' => 'Otkazano',
                                        default => ucfirst($state),
                                    })
                                    ->color(fn (string $state): string => match ($state) {
                                        'pending' => 'secondary', // gray
                                        'confirmed' => 'info', // blue
                                        'preparing' => 'warning', // orange/amber
                                        'ready' => 'success', // brand/purple
                                        'picked_up' => 'warning', // amber/yellow
                                        'delivered' => 'success', // green
                                        'cancelled' => 'danger', // red
                                        default => 'secondary',
                                    }),
                                TextEntry::make('created_at')
                                    ->label('Kreirano')
                                    ->dateTime(),
                                TextEntry::make('subtotal')
                                    ->label('Međuzbir')
                                    ->formatStateUsing(
                                        fn (mixed $state): string => number_format((float) $state, 2, ',', '.').' RSD',
                                    ),
                                TextEntry::make('delivery_fee')
                                    ->label('Dostava')
                                    ->formatStateUsing(
                                        fn (mixed $state): string => number_format((float) $state, 2, ',', '.').' RSD',
                                    ),
                                TextEntry::make('total')
                                    ->label('Ukupno')
                                    ->formatStateUsing(
                                        fn (mixed $state): string => number_format((float) $state, 2, ',', '.').' RSD',
                                    ),
                            ]),
                    ]),
                Section::make('Podaci o kupcu')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextEntry::make('user.name')
                                    ->label('Kupac'),
                                TextEntry::make('user.phone')
                                    ->label('Telefon'),
                                TextEntry::make('delivery_address')
                                    ->label('Adresa dostave')
                                    ->columnSpanFull(),
                            ]),
                    ]),
                Section::make('Stavke narudžbine')
                    ->schema([
                        ViewEntry::make('items_table')
                            ->label('')
                            ->view('filament.vendor.order-items-table')
                            ->columnSpanFull(),
                    ]),
                Section::make('Dostava i prodavnica')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextEntry::make('notes')
                                    ->label('Napomena za dostavu')
                                    ->placeholder('Nema posebnih napomena.')
                                    ->columnSpanFull(),
                                TextEntry::make('deliveryTask.courier.name')
                                    ->label('Kurir'),
                                TextEntry::make('store.name_sr')
                                    ->label('Prodavnica'),
                                TextEntry::make('store.address')
                                    ->label('Adresa prodavnice'),
                                TextEntry::make('store.city')
                                    ->label('Grad'),
                            ]),
                    ]),
            ]);
    }

    protected function getFooterWidgets(): array
    {
        return [];
    }
}
