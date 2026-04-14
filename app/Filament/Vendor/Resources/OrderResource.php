<?php

namespace App\Filament\Vendor\Resources;

use App\Filament\Vendor\Resources\OrderResource\Pages;
use App\Models\Order;
use App\Support\VendorStore;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Narudžbine';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    public static function getModelLabel(): string
    {
        return 'narudžbinu';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Narudžbine';
    }

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return 'Narudžbine';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('Narudžbina')
                    ->formatStateUsing(fn (?string $state): string => $state ? substr($state, 0, 8) : '')
                    ->searchable()
                    ->sortable()
                    ->url(fn (Order $record): string => Pages\ViewOrder::getUrl([$record]))
                    ->openUrlInNewTab(false),
                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
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
                        'pending' => 'secondary',
                        'confirmed' => 'info',
                        'preparing' => 'warning',
                        'ready' => 'success',
                        'picked_up' => 'primary',
                        'delivered' => 'success',
                        'cancelled' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('total')
                    ->label('Ukupno')
                    ->money('RSD', true)
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Kreirano')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Kupac')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('items_count')
                    ->label('Stavki')
                    ->counts('items')
                    ->visibleFrom('md'),
            ])
            ->stackedOnMobile()
            ->filters([
                Tables\Filters\Filter::make('scope')
                    ->label('Prikaži')
                    ->form([
                        Select::make('scope')
                            ->options([
                                'active' => 'Aktivne narudžbine',
                                'all' => 'Sve',
                            ])
                            ->default('active'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        if (($data['scope'] ?? 'active') === 'active') {
                            return $query->whereIn('status', ['pending', 'confirmed', 'preparing', 'ready']);
                        }

                        return $query;
                    }),
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'pending' => 'Na čekanju',
                        'confirmed' => 'Potvrđeno',
                        'preparing' => 'U pripremi',
                        'ready' => 'Spremno',
                        'completed' => 'Završeno',
                        'cancelled' => 'Otkazano',
                    ]),
                Tables\Filters\Filter::make('created_from')
                    ->label('Od datuma')
                    ->form([
                        DatePicker::make('created_from'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->when(
                            $data['created_from'] ?? null,
                            fn (Builder $q, $date): Builder => $q->whereDate('created_at', '>=', $date),
                        );
                    }),
                Tables\Filters\Filter::make('created_until')
                    ->label('Do datuma')
                    ->form([
                        DatePicker::make('created_until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->when(
                            $data['created_until'] ?? null,
                            fn (Builder $q, $date): Builder => $q->whereDate('created_at', '<=', $date),
                        );
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->poll('5s')
            ->actions([
                ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->with(['user', 'items.product', 'store', 'deliveryTask.courier']);

        $storeId = VendorStore::scopedStoreId();

        if ($storeId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('store_id', $storeId)->where('source', 'online');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function canViewAny(?Model $record = null): bool
    {
        return VendorStore::scopedStoreId() !== null;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrders::route('/'),
            'view' => Pages\ViewOrder::route('/{record}'),
        ];
    }
}
