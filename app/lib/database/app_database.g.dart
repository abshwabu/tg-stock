// GENERATED CODE - DO NOT MODIFY BY HAND

part of 'app_database.dart';

// ignore_for_file: type=lint
class $ProductsTable extends Products with TableInfo<$ProductsTable, Product> {
  @override
  final GeneratedDatabase attachedDatabase;
  final String? _alias;
  $ProductsTable(this.attachedDatabase, [this._alias]);
  static const VerificationMeta _idMeta = const VerificationMeta('id');
  @override
  late final GeneratedColumn<String> id = GeneratedColumn<String>(
    'id',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _nameMeta = const VerificationMeta('name');
  @override
  late final GeneratedColumn<String> name = GeneratedColumn<String>(
    'name',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _skuMeta = const VerificationMeta('sku');
  @override
  late final GeneratedColumn<String> sku = GeneratedColumn<String>(
    'sku',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _unitMeta = const VerificationMeta('unit');
  @override
  late final GeneratedColumn<String> unit = GeneratedColumn<String>(
    'unit',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
    defaultValue: const Constant('pcs'),
  );
  static const VerificationMeta _kindMeta = const VerificationMeta('kind');
  @override
  late final GeneratedColumn<String> kind = GeneratedColumn<String>(
    'kind',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
    defaultValue: const Constant('stockable'),
  );
  static const VerificationMeta _costPriceCentsMeta = const VerificationMeta(
    'costPriceCents',
  );
  @override
  late final GeneratedColumn<int> costPriceCents = GeneratedColumn<int>(
    'cost_price_cents',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
    defaultValue: const Constant(0),
  );
  static const VerificationMeta _sellPriceCentsMeta = const VerificationMeta(
    'sellPriceCents',
  );
  @override
  late final GeneratedColumn<int> sellPriceCents = GeneratedColumn<int>(
    'sell_price_cents',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
    defaultValue: const Constant(0),
  );
  static const VerificationMeta _askPriceMeta = const VerificationMeta(
    'askPrice',
  );
  @override
  late final GeneratedColumn<bool> askPrice = GeneratedColumn<bool>(
    'ask_price',
    aliasedName,
    false,
    type: DriftSqlType.bool,
    requiredDuringInsert: false,
    defaultConstraints: GeneratedColumn.constraintIsAlways(
      'CHECK ("ask_price" IN (0, 1))',
    ),
    defaultValue: const Constant(false),
  );
  static const VerificationMeta _lowStockAtMilliMeta = const VerificationMeta(
    'lowStockAtMilli',
  );
  @override
  late final GeneratedColumn<int> lowStockAtMilli = GeneratedColumn<int>(
    'low_stock_at_milli',
    aliasedName,
    true,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _isActiveMeta = const VerificationMeta(
    'isActive',
  );
  @override
  late final GeneratedColumn<bool> isActive = GeneratedColumn<bool>(
    'is_active',
    aliasedName,
    false,
    type: DriftSqlType.bool,
    requiredDuringInsert: false,
    defaultConstraints: GeneratedColumn.constraintIsAlways(
      'CHECK ("is_active" IN (0, 1))',
    ),
    defaultValue: const Constant(true),
  );
  static const VerificationMeta _kindChangedAtMsMeta = const VerificationMeta(
    'kindChangedAtMs',
  );
  @override
  late final GeneratedColumn<int> kindChangedAtMs = GeneratedColumn<int>(
    'kind_changed_at_ms',
    aliasedName,
    true,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _updatedAtMsMeta = const VerificationMeta(
    'updatedAtMs',
  );
  @override
  late final GeneratedColumn<int> updatedAtMs = GeneratedColumn<int>(
    'updated_at_ms',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _deletedAtMsMeta = const VerificationMeta(
    'deletedAtMs',
  );
  @override
  late final GeneratedColumn<int> deletedAtMs = GeneratedColumn<int>(
    'deleted_at_ms',
    aliasedName,
    true,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
  );
  @override
  List<GeneratedColumn> get $columns => [
    id,
    name,
    sku,
    unit,
    kind,
    costPriceCents,
    sellPriceCents,
    askPrice,
    lowStockAtMilli,
    isActive,
    kindChangedAtMs,
    updatedAtMs,
    deletedAtMs,
  ];
  @override
  String get aliasedName => _alias ?? actualTableName;
  @override
  String get actualTableName => $name;
  static const String $name = 'products';
  @override
  VerificationContext validateIntegrity(
    Insertable<Product> instance, {
    bool isInserting = false,
  }) {
    final context = VerificationContext();
    final data = instance.toColumns(true);
    if (data.containsKey('id')) {
      context.handle(_idMeta, id.isAcceptableOrUnknown(data['id']!, _idMeta));
    } else if (isInserting) {
      context.missing(_idMeta);
    }
    if (data.containsKey('name')) {
      context.handle(
        _nameMeta,
        name.isAcceptableOrUnknown(data['name']!, _nameMeta),
      );
    } else if (isInserting) {
      context.missing(_nameMeta);
    }
    if (data.containsKey('sku')) {
      context.handle(
        _skuMeta,
        sku.isAcceptableOrUnknown(data['sku']!, _skuMeta),
      );
    }
    if (data.containsKey('unit')) {
      context.handle(
        _unitMeta,
        unit.isAcceptableOrUnknown(data['unit']!, _unitMeta),
      );
    }
    if (data.containsKey('kind')) {
      context.handle(
        _kindMeta,
        kind.isAcceptableOrUnknown(data['kind']!, _kindMeta),
      );
    }
    if (data.containsKey('cost_price_cents')) {
      context.handle(
        _costPriceCentsMeta,
        costPriceCents.isAcceptableOrUnknown(
          data['cost_price_cents']!,
          _costPriceCentsMeta,
        ),
      );
    }
    if (data.containsKey('sell_price_cents')) {
      context.handle(
        _sellPriceCentsMeta,
        sellPriceCents.isAcceptableOrUnknown(
          data['sell_price_cents']!,
          _sellPriceCentsMeta,
        ),
      );
    }
    if (data.containsKey('ask_price')) {
      context.handle(
        _askPriceMeta,
        askPrice.isAcceptableOrUnknown(data['ask_price']!, _askPriceMeta),
      );
    }
    if (data.containsKey('low_stock_at_milli')) {
      context.handle(
        _lowStockAtMilliMeta,
        lowStockAtMilli.isAcceptableOrUnknown(
          data['low_stock_at_milli']!,
          _lowStockAtMilliMeta,
        ),
      );
    }
    if (data.containsKey('is_active')) {
      context.handle(
        _isActiveMeta,
        isActive.isAcceptableOrUnknown(data['is_active']!, _isActiveMeta),
      );
    }
    if (data.containsKey('kind_changed_at_ms')) {
      context.handle(
        _kindChangedAtMsMeta,
        kindChangedAtMs.isAcceptableOrUnknown(
          data['kind_changed_at_ms']!,
          _kindChangedAtMsMeta,
        ),
      );
    }
    if (data.containsKey('updated_at_ms')) {
      context.handle(
        _updatedAtMsMeta,
        updatedAtMs.isAcceptableOrUnknown(
          data['updated_at_ms']!,
          _updatedAtMsMeta,
        ),
      );
    } else if (isInserting) {
      context.missing(_updatedAtMsMeta);
    }
    if (data.containsKey('deleted_at_ms')) {
      context.handle(
        _deletedAtMsMeta,
        deletedAtMs.isAcceptableOrUnknown(
          data['deleted_at_ms']!,
          _deletedAtMsMeta,
        ),
      );
    }
    return context;
  }

  @override
  Set<GeneratedColumn> get $primaryKey => {id};
  @override
  Product map(Map<String, dynamic> data, {String? tablePrefix}) {
    final effectivePrefix = tablePrefix != null ? '$tablePrefix.' : '';
    return Product(
      id: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}id'],
      )!,
      name: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}name'],
      )!,
      sku: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}sku'],
      ),
      unit: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}unit'],
      )!,
      kind: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}kind'],
      )!,
      costPriceCents: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}cost_price_cents'],
      )!,
      sellPriceCents: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}sell_price_cents'],
      )!,
      askPrice: attachedDatabase.typeMapping.read(
        DriftSqlType.bool,
        data['${effectivePrefix}ask_price'],
      )!,
      lowStockAtMilli: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}low_stock_at_milli'],
      ),
      isActive: attachedDatabase.typeMapping.read(
        DriftSqlType.bool,
        data['${effectivePrefix}is_active'],
      )!,
      kindChangedAtMs: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}kind_changed_at_ms'],
      ),
      updatedAtMs: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}updated_at_ms'],
      )!,
      deletedAtMs: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}deleted_at_ms'],
      ),
    );
  }

  @override
  $ProductsTable createAlias(String alias) {
    return $ProductsTable(attachedDatabase, alias);
  }
}

class Product extends DataClass implements Insertable<Product> {
  final String id;
  final String name;
  final String? sku;
  final String unit;
  final String kind;
  final int costPriceCents;
  final int sellPriceCents;
  final bool askPrice;
  final int? lowStockAtMilli;
  final bool isActive;
  final int? kindChangedAtMs;
  final int updatedAtMs;
  final int? deletedAtMs;
  const Product({
    required this.id,
    required this.name,
    this.sku,
    required this.unit,
    required this.kind,
    required this.costPriceCents,
    required this.sellPriceCents,
    required this.askPrice,
    this.lowStockAtMilli,
    required this.isActive,
    this.kindChangedAtMs,
    required this.updatedAtMs,
    this.deletedAtMs,
  });
  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    map['id'] = Variable<String>(id);
    map['name'] = Variable<String>(name);
    if (!nullToAbsent || sku != null) {
      map['sku'] = Variable<String>(sku);
    }
    map['unit'] = Variable<String>(unit);
    map['kind'] = Variable<String>(kind);
    map['cost_price_cents'] = Variable<int>(costPriceCents);
    map['sell_price_cents'] = Variable<int>(sellPriceCents);
    map['ask_price'] = Variable<bool>(askPrice);
    if (!nullToAbsent || lowStockAtMilli != null) {
      map['low_stock_at_milli'] = Variable<int>(lowStockAtMilli);
    }
    map['is_active'] = Variable<bool>(isActive);
    if (!nullToAbsent || kindChangedAtMs != null) {
      map['kind_changed_at_ms'] = Variable<int>(kindChangedAtMs);
    }
    map['updated_at_ms'] = Variable<int>(updatedAtMs);
    if (!nullToAbsent || deletedAtMs != null) {
      map['deleted_at_ms'] = Variable<int>(deletedAtMs);
    }
    return map;
  }

  ProductsCompanion toCompanion(bool nullToAbsent) {
    return ProductsCompanion(
      id: Value(id),
      name: Value(name),
      sku: sku == null && nullToAbsent ? const Value.absent() : Value(sku),
      unit: Value(unit),
      kind: Value(kind),
      costPriceCents: Value(costPriceCents),
      sellPriceCents: Value(sellPriceCents),
      askPrice: Value(askPrice),
      lowStockAtMilli: lowStockAtMilli == null && nullToAbsent
          ? const Value.absent()
          : Value(lowStockAtMilli),
      isActive: Value(isActive),
      kindChangedAtMs: kindChangedAtMs == null && nullToAbsent
          ? const Value.absent()
          : Value(kindChangedAtMs),
      updatedAtMs: Value(updatedAtMs),
      deletedAtMs: deletedAtMs == null && nullToAbsent
          ? const Value.absent()
          : Value(deletedAtMs),
    );
  }

  factory Product.fromJson(
    Map<String, dynamic> json, {
    ValueSerializer? serializer,
  }) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return Product(
      id: serializer.fromJson<String>(json['id']),
      name: serializer.fromJson<String>(json['name']),
      sku: serializer.fromJson<String?>(json['sku']),
      unit: serializer.fromJson<String>(json['unit']),
      kind: serializer.fromJson<String>(json['kind']),
      costPriceCents: serializer.fromJson<int>(json['costPriceCents']),
      sellPriceCents: serializer.fromJson<int>(json['sellPriceCents']),
      askPrice: serializer.fromJson<bool>(json['askPrice']),
      lowStockAtMilli: serializer.fromJson<int?>(json['lowStockAtMilli']),
      isActive: serializer.fromJson<bool>(json['isActive']),
      kindChangedAtMs: serializer.fromJson<int?>(json['kindChangedAtMs']),
      updatedAtMs: serializer.fromJson<int>(json['updatedAtMs']),
      deletedAtMs: serializer.fromJson<int?>(json['deletedAtMs']),
    );
  }
  @override
  Map<String, dynamic> toJson({ValueSerializer? serializer}) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return <String, dynamic>{
      'id': serializer.toJson<String>(id),
      'name': serializer.toJson<String>(name),
      'sku': serializer.toJson<String?>(sku),
      'unit': serializer.toJson<String>(unit),
      'kind': serializer.toJson<String>(kind),
      'costPriceCents': serializer.toJson<int>(costPriceCents),
      'sellPriceCents': serializer.toJson<int>(sellPriceCents),
      'askPrice': serializer.toJson<bool>(askPrice),
      'lowStockAtMilli': serializer.toJson<int?>(lowStockAtMilli),
      'isActive': serializer.toJson<bool>(isActive),
      'kindChangedAtMs': serializer.toJson<int?>(kindChangedAtMs),
      'updatedAtMs': serializer.toJson<int>(updatedAtMs),
      'deletedAtMs': serializer.toJson<int?>(deletedAtMs),
    };
  }

  Product copyWith({
    String? id,
    String? name,
    Value<String?> sku = const Value.absent(),
    String? unit,
    String? kind,
    int? costPriceCents,
    int? sellPriceCents,
    bool? askPrice,
    Value<int?> lowStockAtMilli = const Value.absent(),
    bool? isActive,
    Value<int?> kindChangedAtMs = const Value.absent(),
    int? updatedAtMs,
    Value<int?> deletedAtMs = const Value.absent(),
  }) => Product(
    id: id ?? this.id,
    name: name ?? this.name,
    sku: sku.present ? sku.value : this.sku,
    unit: unit ?? this.unit,
    kind: kind ?? this.kind,
    costPriceCents: costPriceCents ?? this.costPriceCents,
    sellPriceCents: sellPriceCents ?? this.sellPriceCents,
    askPrice: askPrice ?? this.askPrice,
    lowStockAtMilli: lowStockAtMilli.present
        ? lowStockAtMilli.value
        : this.lowStockAtMilli,
    isActive: isActive ?? this.isActive,
    kindChangedAtMs: kindChangedAtMs.present
        ? kindChangedAtMs.value
        : this.kindChangedAtMs,
    updatedAtMs: updatedAtMs ?? this.updatedAtMs,
    deletedAtMs: deletedAtMs.present ? deletedAtMs.value : this.deletedAtMs,
  );
  Product copyWithCompanion(ProductsCompanion data) {
    return Product(
      id: data.id.present ? data.id.value : this.id,
      name: data.name.present ? data.name.value : this.name,
      sku: data.sku.present ? data.sku.value : this.sku,
      unit: data.unit.present ? data.unit.value : this.unit,
      kind: data.kind.present ? data.kind.value : this.kind,
      costPriceCents: data.costPriceCents.present
          ? data.costPriceCents.value
          : this.costPriceCents,
      sellPriceCents: data.sellPriceCents.present
          ? data.sellPriceCents.value
          : this.sellPriceCents,
      askPrice: data.askPrice.present ? data.askPrice.value : this.askPrice,
      lowStockAtMilli: data.lowStockAtMilli.present
          ? data.lowStockAtMilli.value
          : this.lowStockAtMilli,
      isActive: data.isActive.present ? data.isActive.value : this.isActive,
      kindChangedAtMs: data.kindChangedAtMs.present
          ? data.kindChangedAtMs.value
          : this.kindChangedAtMs,
      updatedAtMs: data.updatedAtMs.present
          ? data.updatedAtMs.value
          : this.updatedAtMs,
      deletedAtMs: data.deletedAtMs.present
          ? data.deletedAtMs.value
          : this.deletedAtMs,
    );
  }

  @override
  String toString() {
    return (StringBuffer('Product(')
          ..write('id: $id, ')
          ..write('name: $name, ')
          ..write('sku: $sku, ')
          ..write('unit: $unit, ')
          ..write('kind: $kind, ')
          ..write('costPriceCents: $costPriceCents, ')
          ..write('sellPriceCents: $sellPriceCents, ')
          ..write('askPrice: $askPrice, ')
          ..write('lowStockAtMilli: $lowStockAtMilli, ')
          ..write('isActive: $isActive, ')
          ..write('kindChangedAtMs: $kindChangedAtMs, ')
          ..write('updatedAtMs: $updatedAtMs, ')
          ..write('deletedAtMs: $deletedAtMs')
          ..write(')'))
        .toString();
  }

  @override
  int get hashCode => Object.hash(
    id,
    name,
    sku,
    unit,
    kind,
    costPriceCents,
    sellPriceCents,
    askPrice,
    lowStockAtMilli,
    isActive,
    kindChangedAtMs,
    updatedAtMs,
    deletedAtMs,
  );
  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      (other is Product &&
          other.id == this.id &&
          other.name == this.name &&
          other.sku == this.sku &&
          other.unit == this.unit &&
          other.kind == this.kind &&
          other.costPriceCents == this.costPriceCents &&
          other.sellPriceCents == this.sellPriceCents &&
          other.askPrice == this.askPrice &&
          other.lowStockAtMilli == this.lowStockAtMilli &&
          other.isActive == this.isActive &&
          other.kindChangedAtMs == this.kindChangedAtMs &&
          other.updatedAtMs == this.updatedAtMs &&
          other.deletedAtMs == this.deletedAtMs);
}

class ProductsCompanion extends UpdateCompanion<Product> {
  final Value<String> id;
  final Value<String> name;
  final Value<String?> sku;
  final Value<String> unit;
  final Value<String> kind;
  final Value<int> costPriceCents;
  final Value<int> sellPriceCents;
  final Value<bool> askPrice;
  final Value<int?> lowStockAtMilli;
  final Value<bool> isActive;
  final Value<int?> kindChangedAtMs;
  final Value<int> updatedAtMs;
  final Value<int?> deletedAtMs;
  final Value<int> rowid;
  const ProductsCompanion({
    this.id = const Value.absent(),
    this.name = const Value.absent(),
    this.sku = const Value.absent(),
    this.unit = const Value.absent(),
    this.kind = const Value.absent(),
    this.costPriceCents = const Value.absent(),
    this.sellPriceCents = const Value.absent(),
    this.askPrice = const Value.absent(),
    this.lowStockAtMilli = const Value.absent(),
    this.isActive = const Value.absent(),
    this.kindChangedAtMs = const Value.absent(),
    this.updatedAtMs = const Value.absent(),
    this.deletedAtMs = const Value.absent(),
    this.rowid = const Value.absent(),
  });
  ProductsCompanion.insert({
    required String id,
    required String name,
    this.sku = const Value.absent(),
    this.unit = const Value.absent(),
    this.kind = const Value.absent(),
    this.costPriceCents = const Value.absent(),
    this.sellPriceCents = const Value.absent(),
    this.askPrice = const Value.absent(),
    this.lowStockAtMilli = const Value.absent(),
    this.isActive = const Value.absent(),
    this.kindChangedAtMs = const Value.absent(),
    required int updatedAtMs,
    this.deletedAtMs = const Value.absent(),
    this.rowid = const Value.absent(),
  }) : id = Value(id),
       name = Value(name),
       updatedAtMs = Value(updatedAtMs);
  static Insertable<Product> custom({
    Expression<String>? id,
    Expression<String>? name,
    Expression<String>? sku,
    Expression<String>? unit,
    Expression<String>? kind,
    Expression<int>? costPriceCents,
    Expression<int>? sellPriceCents,
    Expression<bool>? askPrice,
    Expression<int>? lowStockAtMilli,
    Expression<bool>? isActive,
    Expression<int>? kindChangedAtMs,
    Expression<int>? updatedAtMs,
    Expression<int>? deletedAtMs,
    Expression<int>? rowid,
  }) {
    return RawValuesInsertable({
      if (id != null) 'id': id,
      if (name != null) 'name': name,
      if (sku != null) 'sku': sku,
      if (unit != null) 'unit': unit,
      if (kind != null) 'kind': kind,
      if (costPriceCents != null) 'cost_price_cents': costPriceCents,
      if (sellPriceCents != null) 'sell_price_cents': sellPriceCents,
      if (askPrice != null) 'ask_price': askPrice,
      if (lowStockAtMilli != null) 'low_stock_at_milli': lowStockAtMilli,
      if (isActive != null) 'is_active': isActive,
      if (kindChangedAtMs != null) 'kind_changed_at_ms': kindChangedAtMs,
      if (updatedAtMs != null) 'updated_at_ms': updatedAtMs,
      if (deletedAtMs != null) 'deleted_at_ms': deletedAtMs,
      if (rowid != null) 'rowid': rowid,
    });
  }

  ProductsCompanion copyWith({
    Value<String>? id,
    Value<String>? name,
    Value<String?>? sku,
    Value<String>? unit,
    Value<String>? kind,
    Value<int>? costPriceCents,
    Value<int>? sellPriceCents,
    Value<bool>? askPrice,
    Value<int?>? lowStockAtMilli,
    Value<bool>? isActive,
    Value<int?>? kindChangedAtMs,
    Value<int>? updatedAtMs,
    Value<int?>? deletedAtMs,
    Value<int>? rowid,
  }) {
    return ProductsCompanion(
      id: id ?? this.id,
      name: name ?? this.name,
      sku: sku ?? this.sku,
      unit: unit ?? this.unit,
      kind: kind ?? this.kind,
      costPriceCents: costPriceCents ?? this.costPriceCents,
      sellPriceCents: sellPriceCents ?? this.sellPriceCents,
      askPrice: askPrice ?? this.askPrice,
      lowStockAtMilli: lowStockAtMilli ?? this.lowStockAtMilli,
      isActive: isActive ?? this.isActive,
      kindChangedAtMs: kindChangedAtMs ?? this.kindChangedAtMs,
      updatedAtMs: updatedAtMs ?? this.updatedAtMs,
      deletedAtMs: deletedAtMs ?? this.deletedAtMs,
      rowid: rowid ?? this.rowid,
    );
  }

  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    if (id.present) {
      map['id'] = Variable<String>(id.value);
    }
    if (name.present) {
      map['name'] = Variable<String>(name.value);
    }
    if (sku.present) {
      map['sku'] = Variable<String>(sku.value);
    }
    if (unit.present) {
      map['unit'] = Variable<String>(unit.value);
    }
    if (kind.present) {
      map['kind'] = Variable<String>(kind.value);
    }
    if (costPriceCents.present) {
      map['cost_price_cents'] = Variable<int>(costPriceCents.value);
    }
    if (sellPriceCents.present) {
      map['sell_price_cents'] = Variable<int>(sellPriceCents.value);
    }
    if (askPrice.present) {
      map['ask_price'] = Variable<bool>(askPrice.value);
    }
    if (lowStockAtMilli.present) {
      map['low_stock_at_milli'] = Variable<int>(lowStockAtMilli.value);
    }
    if (isActive.present) {
      map['is_active'] = Variable<bool>(isActive.value);
    }
    if (kindChangedAtMs.present) {
      map['kind_changed_at_ms'] = Variable<int>(kindChangedAtMs.value);
    }
    if (updatedAtMs.present) {
      map['updated_at_ms'] = Variable<int>(updatedAtMs.value);
    }
    if (deletedAtMs.present) {
      map['deleted_at_ms'] = Variable<int>(deletedAtMs.value);
    }
    if (rowid.present) {
      map['rowid'] = Variable<int>(rowid.value);
    }
    return map;
  }

  @override
  String toString() {
    return (StringBuffer('ProductsCompanion(')
          ..write('id: $id, ')
          ..write('name: $name, ')
          ..write('sku: $sku, ')
          ..write('unit: $unit, ')
          ..write('kind: $kind, ')
          ..write('costPriceCents: $costPriceCents, ')
          ..write('sellPriceCents: $sellPriceCents, ')
          ..write('askPrice: $askPrice, ')
          ..write('lowStockAtMilli: $lowStockAtMilli, ')
          ..write('isActive: $isActive, ')
          ..write('kindChangedAtMs: $kindChangedAtMs, ')
          ..write('updatedAtMs: $updatedAtMs, ')
          ..write('deletedAtMs: $deletedAtMs, ')
          ..write('rowid: $rowid')
          ..write(')'))
        .toString();
  }
}

class $ProductMaterialsTable extends ProductMaterials
    with TableInfo<$ProductMaterialsTable, ProductMaterial> {
  @override
  final GeneratedDatabase attachedDatabase;
  final String? _alias;
  $ProductMaterialsTable(this.attachedDatabase, [this._alias]);
  static const VerificationMeta _productIdMeta = const VerificationMeta(
    'productId',
  );
  @override
  late final GeneratedColumn<String> productId = GeneratedColumn<String>(
    'product_id',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _materialProductIdMeta = const VerificationMeta(
    'materialProductId',
  );
  @override
  late final GeneratedColumn<String> materialProductId =
      GeneratedColumn<String>(
        'material_product_id',
        aliasedName,
        false,
        type: DriftSqlType.string,
        requiredDuringInsert: true,
      );
  static const VerificationMeta _qtyMilliMeta = const VerificationMeta(
    'qtyMilli',
  );
  @override
  late final GeneratedColumn<int> qtyMilli = GeneratedColumn<int>(
    'qty_milli',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: true,
  );
  @override
  List<GeneratedColumn> get $columns => [
    productId,
    materialProductId,
    qtyMilli,
  ];
  @override
  String get aliasedName => _alias ?? actualTableName;
  @override
  String get actualTableName => $name;
  static const String $name = 'product_materials';
  @override
  VerificationContext validateIntegrity(
    Insertable<ProductMaterial> instance, {
    bool isInserting = false,
  }) {
    final context = VerificationContext();
    final data = instance.toColumns(true);
    if (data.containsKey('product_id')) {
      context.handle(
        _productIdMeta,
        productId.isAcceptableOrUnknown(data['product_id']!, _productIdMeta),
      );
    } else if (isInserting) {
      context.missing(_productIdMeta);
    }
    if (data.containsKey('material_product_id')) {
      context.handle(
        _materialProductIdMeta,
        materialProductId.isAcceptableOrUnknown(
          data['material_product_id']!,
          _materialProductIdMeta,
        ),
      );
    } else if (isInserting) {
      context.missing(_materialProductIdMeta);
    }
    if (data.containsKey('qty_milli')) {
      context.handle(
        _qtyMilliMeta,
        qtyMilli.isAcceptableOrUnknown(data['qty_milli']!, _qtyMilliMeta),
      );
    } else if (isInserting) {
      context.missing(_qtyMilliMeta);
    }
    return context;
  }

  @override
  Set<GeneratedColumn> get $primaryKey => {productId, materialProductId};
  @override
  ProductMaterial map(Map<String, dynamic> data, {String? tablePrefix}) {
    final effectivePrefix = tablePrefix != null ? '$tablePrefix.' : '';
    return ProductMaterial(
      productId: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}product_id'],
      )!,
      materialProductId: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}material_product_id'],
      )!,
      qtyMilli: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}qty_milli'],
      )!,
    );
  }

  @override
  $ProductMaterialsTable createAlias(String alias) {
    return $ProductMaterialsTable(attachedDatabase, alias);
  }
}

class ProductMaterial extends DataClass implements Insertable<ProductMaterial> {
  final String productId;
  final String materialProductId;
  final int qtyMilli;
  const ProductMaterial({
    required this.productId,
    required this.materialProductId,
    required this.qtyMilli,
  });
  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    map['product_id'] = Variable<String>(productId);
    map['material_product_id'] = Variable<String>(materialProductId);
    map['qty_milli'] = Variable<int>(qtyMilli);
    return map;
  }

  ProductMaterialsCompanion toCompanion(bool nullToAbsent) {
    return ProductMaterialsCompanion(
      productId: Value(productId),
      materialProductId: Value(materialProductId),
      qtyMilli: Value(qtyMilli),
    );
  }

  factory ProductMaterial.fromJson(
    Map<String, dynamic> json, {
    ValueSerializer? serializer,
  }) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return ProductMaterial(
      productId: serializer.fromJson<String>(json['productId']),
      materialProductId: serializer.fromJson<String>(json['materialProductId']),
      qtyMilli: serializer.fromJson<int>(json['qtyMilli']),
    );
  }
  @override
  Map<String, dynamic> toJson({ValueSerializer? serializer}) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return <String, dynamic>{
      'productId': serializer.toJson<String>(productId),
      'materialProductId': serializer.toJson<String>(materialProductId),
      'qtyMilli': serializer.toJson<int>(qtyMilli),
    };
  }

  ProductMaterial copyWith({
    String? productId,
    String? materialProductId,
    int? qtyMilli,
  }) => ProductMaterial(
    productId: productId ?? this.productId,
    materialProductId: materialProductId ?? this.materialProductId,
    qtyMilli: qtyMilli ?? this.qtyMilli,
  );
  ProductMaterial copyWithCompanion(ProductMaterialsCompanion data) {
    return ProductMaterial(
      productId: data.productId.present ? data.productId.value : this.productId,
      materialProductId: data.materialProductId.present
          ? data.materialProductId.value
          : this.materialProductId,
      qtyMilli: data.qtyMilli.present ? data.qtyMilli.value : this.qtyMilli,
    );
  }

  @override
  String toString() {
    return (StringBuffer('ProductMaterial(')
          ..write('productId: $productId, ')
          ..write('materialProductId: $materialProductId, ')
          ..write('qtyMilli: $qtyMilli')
          ..write(')'))
        .toString();
  }

  @override
  int get hashCode => Object.hash(productId, materialProductId, qtyMilli);
  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      (other is ProductMaterial &&
          other.productId == this.productId &&
          other.materialProductId == this.materialProductId &&
          other.qtyMilli == this.qtyMilli);
}

class ProductMaterialsCompanion extends UpdateCompanion<ProductMaterial> {
  final Value<String> productId;
  final Value<String> materialProductId;
  final Value<int> qtyMilli;
  final Value<int> rowid;
  const ProductMaterialsCompanion({
    this.productId = const Value.absent(),
    this.materialProductId = const Value.absent(),
    this.qtyMilli = const Value.absent(),
    this.rowid = const Value.absent(),
  });
  ProductMaterialsCompanion.insert({
    required String productId,
    required String materialProductId,
    required int qtyMilli,
    this.rowid = const Value.absent(),
  }) : productId = Value(productId),
       materialProductId = Value(materialProductId),
       qtyMilli = Value(qtyMilli);
  static Insertable<ProductMaterial> custom({
    Expression<String>? productId,
    Expression<String>? materialProductId,
    Expression<int>? qtyMilli,
    Expression<int>? rowid,
  }) {
    return RawValuesInsertable({
      if (productId != null) 'product_id': productId,
      if (materialProductId != null) 'material_product_id': materialProductId,
      if (qtyMilli != null) 'qty_milli': qtyMilli,
      if (rowid != null) 'rowid': rowid,
    });
  }

  ProductMaterialsCompanion copyWith({
    Value<String>? productId,
    Value<String>? materialProductId,
    Value<int>? qtyMilli,
    Value<int>? rowid,
  }) {
    return ProductMaterialsCompanion(
      productId: productId ?? this.productId,
      materialProductId: materialProductId ?? this.materialProductId,
      qtyMilli: qtyMilli ?? this.qtyMilli,
      rowid: rowid ?? this.rowid,
    );
  }

  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    if (productId.present) {
      map['product_id'] = Variable<String>(productId.value);
    }
    if (materialProductId.present) {
      map['material_product_id'] = Variable<String>(materialProductId.value);
    }
    if (qtyMilli.present) {
      map['qty_milli'] = Variable<int>(qtyMilli.value);
    }
    if (rowid.present) {
      map['rowid'] = Variable<int>(rowid.value);
    }
    return map;
  }

  @override
  String toString() {
    return (StringBuffer('ProductMaterialsCompanion(')
          ..write('productId: $productId, ')
          ..write('materialProductId: $materialProductId, ')
          ..write('qtyMilli: $qtyMilli, ')
          ..write('rowid: $rowid')
          ..write(')'))
        .toString();
  }
}

class $MovementsTable extends Movements
    with TableInfo<$MovementsTable, Movement> {
  @override
  final GeneratedDatabase attachedDatabase;
  final String? _alias;
  $MovementsTable(this.attachedDatabase, [this._alias]);
  static const VerificationMeta _idMeta = const VerificationMeta('id');
  @override
  late final GeneratedColumn<String> id = GeneratedColumn<String>(
    'id',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _productIdMeta = const VerificationMeta(
    'productId',
  );
  @override
  late final GeneratedColumn<String> productId = GeneratedColumn<String>(
    'product_id',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _saleIdMeta = const VerificationMeta('saleId');
  @override
  late final GeneratedColumn<String> saleId = GeneratedColumn<String>(
    'sale_id',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _locationIdMeta = const VerificationMeta(
    'locationId',
  );
  @override
  late final GeneratedColumn<String> locationId = GeneratedColumn<String>(
    'location_id',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _transferIdMeta = const VerificationMeta(
    'transferId',
  );
  @override
  late final GeneratedColumn<String> transferId = GeneratedColumn<String>(
    'transfer_id',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _sourceMovementIdMeta = const VerificationMeta(
    'sourceMovementId',
  );
  @override
  late final GeneratedColumn<String> sourceMovementId = GeneratedColumn<String>(
    'source_movement_id',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _typeMeta = const VerificationMeta('type');
  @override
  late final GeneratedColumn<String> type = GeneratedColumn<String>(
    'type',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _qtyMilliMeta = const VerificationMeta(
    'qtyMilli',
  );
  @override
  late final GeneratedColumn<int> qtyMilli = GeneratedColumn<int>(
    'qty_milli',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _unitPriceCentsMeta = const VerificationMeta(
    'unitPriceCents',
  );
  @override
  late final GeneratedColumn<int> unitPriceCents = GeneratedColumn<int>(
    'unit_price_cents',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
    defaultValue: const Constant(0),
  );
  static const VerificationMeta _tracksStockMeta = const VerificationMeta(
    'tracksStock',
  );
  @override
  late final GeneratedColumn<bool> tracksStock = GeneratedColumn<bool>(
    'tracks_stock',
    aliasedName,
    false,
    type: DriftSqlType.bool,
    requiredDuringInsert: false,
    defaultConstraints: GeneratedColumn.constraintIsAlways(
      'CHECK ("tracks_stock" IN (0, 1))',
    ),
    defaultValue: const Constant(true),
  );
  static const VerificationMeta _noteMeta = const VerificationMeta('note');
  @override
  late final GeneratedColumn<String> note = GeneratedColumn<String>(
    'note',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _createdAtMsMeta = const VerificationMeta(
    'createdAtMs',
  );
  @override
  late final GeneratedColumn<int> createdAtMs = GeneratedColumn<int>(
    'created_at_ms',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: true,
  );
  @override
  List<GeneratedColumn> get $columns => [
    id,
    productId,
    saleId,
    locationId,
    transferId,
    sourceMovementId,
    type,
    qtyMilli,
    unitPriceCents,
    tracksStock,
    note,
    createdAtMs,
  ];
  @override
  String get aliasedName => _alias ?? actualTableName;
  @override
  String get actualTableName => $name;
  static const String $name = 'movements';
  @override
  VerificationContext validateIntegrity(
    Insertable<Movement> instance, {
    bool isInserting = false,
  }) {
    final context = VerificationContext();
    final data = instance.toColumns(true);
    if (data.containsKey('id')) {
      context.handle(_idMeta, id.isAcceptableOrUnknown(data['id']!, _idMeta));
    } else if (isInserting) {
      context.missing(_idMeta);
    }
    if (data.containsKey('product_id')) {
      context.handle(
        _productIdMeta,
        productId.isAcceptableOrUnknown(data['product_id']!, _productIdMeta),
      );
    } else if (isInserting) {
      context.missing(_productIdMeta);
    }
    if (data.containsKey('sale_id')) {
      context.handle(
        _saleIdMeta,
        saleId.isAcceptableOrUnknown(data['sale_id']!, _saleIdMeta),
      );
    }
    if (data.containsKey('location_id')) {
      context.handle(
        _locationIdMeta,
        locationId.isAcceptableOrUnknown(data['location_id']!, _locationIdMeta),
      );
    }
    if (data.containsKey('transfer_id')) {
      context.handle(
        _transferIdMeta,
        transferId.isAcceptableOrUnknown(data['transfer_id']!, _transferIdMeta),
      );
    }
    if (data.containsKey('source_movement_id')) {
      context.handle(
        _sourceMovementIdMeta,
        sourceMovementId.isAcceptableOrUnknown(
          data['source_movement_id']!,
          _sourceMovementIdMeta,
        ),
      );
    }
    if (data.containsKey('type')) {
      context.handle(
        _typeMeta,
        type.isAcceptableOrUnknown(data['type']!, _typeMeta),
      );
    } else if (isInserting) {
      context.missing(_typeMeta);
    }
    if (data.containsKey('qty_milli')) {
      context.handle(
        _qtyMilliMeta,
        qtyMilli.isAcceptableOrUnknown(data['qty_milli']!, _qtyMilliMeta),
      );
    } else if (isInserting) {
      context.missing(_qtyMilliMeta);
    }
    if (data.containsKey('unit_price_cents')) {
      context.handle(
        _unitPriceCentsMeta,
        unitPriceCents.isAcceptableOrUnknown(
          data['unit_price_cents']!,
          _unitPriceCentsMeta,
        ),
      );
    }
    if (data.containsKey('tracks_stock')) {
      context.handle(
        _tracksStockMeta,
        tracksStock.isAcceptableOrUnknown(
          data['tracks_stock']!,
          _tracksStockMeta,
        ),
      );
    }
    if (data.containsKey('note')) {
      context.handle(
        _noteMeta,
        note.isAcceptableOrUnknown(data['note']!, _noteMeta),
      );
    }
    if (data.containsKey('created_at_ms')) {
      context.handle(
        _createdAtMsMeta,
        createdAtMs.isAcceptableOrUnknown(
          data['created_at_ms']!,
          _createdAtMsMeta,
        ),
      );
    } else if (isInserting) {
      context.missing(_createdAtMsMeta);
    }
    return context;
  }

  @override
  Set<GeneratedColumn> get $primaryKey => {id};
  @override
  Movement map(Map<String, dynamic> data, {String? tablePrefix}) {
    final effectivePrefix = tablePrefix != null ? '$tablePrefix.' : '';
    return Movement(
      id: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}id'],
      )!,
      productId: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}product_id'],
      )!,
      saleId: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}sale_id'],
      ),
      locationId: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}location_id'],
      ),
      transferId: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}transfer_id'],
      ),
      sourceMovementId: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}source_movement_id'],
      ),
      type: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}type'],
      )!,
      qtyMilli: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}qty_milli'],
      )!,
      unitPriceCents: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}unit_price_cents'],
      )!,
      tracksStock: attachedDatabase.typeMapping.read(
        DriftSqlType.bool,
        data['${effectivePrefix}tracks_stock'],
      )!,
      note: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}note'],
      ),
      createdAtMs: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}created_at_ms'],
      )!,
    );
  }

  @override
  $MovementsTable createAlias(String alias) {
    return $MovementsTable(attachedDatabase, alias);
  }
}

class Movement extends DataClass implements Insertable<Movement> {
  final String id;
  final String productId;
  final String? saleId;
  final String? locationId;
  final String? transferId;
  final String? sourceMovementId;
  final String type;
  final int qtyMilli;
  final int unitPriceCents;
  final bool tracksStock;
  final String? note;
  final int createdAtMs;
  const Movement({
    required this.id,
    required this.productId,
    this.saleId,
    this.locationId,
    this.transferId,
    this.sourceMovementId,
    required this.type,
    required this.qtyMilli,
    required this.unitPriceCents,
    required this.tracksStock,
    this.note,
    required this.createdAtMs,
  });
  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    map['id'] = Variable<String>(id);
    map['product_id'] = Variable<String>(productId);
    if (!nullToAbsent || saleId != null) {
      map['sale_id'] = Variable<String>(saleId);
    }
    if (!nullToAbsent || locationId != null) {
      map['location_id'] = Variable<String>(locationId);
    }
    if (!nullToAbsent || transferId != null) {
      map['transfer_id'] = Variable<String>(transferId);
    }
    if (!nullToAbsent || sourceMovementId != null) {
      map['source_movement_id'] = Variable<String>(sourceMovementId);
    }
    map['type'] = Variable<String>(type);
    map['qty_milli'] = Variable<int>(qtyMilli);
    map['unit_price_cents'] = Variable<int>(unitPriceCents);
    map['tracks_stock'] = Variable<bool>(tracksStock);
    if (!nullToAbsent || note != null) {
      map['note'] = Variable<String>(note);
    }
    map['created_at_ms'] = Variable<int>(createdAtMs);
    return map;
  }

  MovementsCompanion toCompanion(bool nullToAbsent) {
    return MovementsCompanion(
      id: Value(id),
      productId: Value(productId),
      saleId: saleId == null && nullToAbsent
          ? const Value.absent()
          : Value(saleId),
      locationId: locationId == null && nullToAbsent
          ? const Value.absent()
          : Value(locationId),
      transferId: transferId == null && nullToAbsent
          ? const Value.absent()
          : Value(transferId),
      sourceMovementId: sourceMovementId == null && nullToAbsent
          ? const Value.absent()
          : Value(sourceMovementId),
      type: Value(type),
      qtyMilli: Value(qtyMilli),
      unitPriceCents: Value(unitPriceCents),
      tracksStock: Value(tracksStock),
      note: note == null && nullToAbsent ? const Value.absent() : Value(note),
      createdAtMs: Value(createdAtMs),
    );
  }

  factory Movement.fromJson(
    Map<String, dynamic> json, {
    ValueSerializer? serializer,
  }) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return Movement(
      id: serializer.fromJson<String>(json['id']),
      productId: serializer.fromJson<String>(json['productId']),
      saleId: serializer.fromJson<String?>(json['saleId']),
      locationId: serializer.fromJson<String?>(json['locationId']),
      transferId: serializer.fromJson<String?>(json['transferId']),
      sourceMovementId: serializer.fromJson<String?>(json['sourceMovementId']),
      type: serializer.fromJson<String>(json['type']),
      qtyMilli: serializer.fromJson<int>(json['qtyMilli']),
      unitPriceCents: serializer.fromJson<int>(json['unitPriceCents']),
      tracksStock: serializer.fromJson<bool>(json['tracksStock']),
      note: serializer.fromJson<String?>(json['note']),
      createdAtMs: serializer.fromJson<int>(json['createdAtMs']),
    );
  }
  @override
  Map<String, dynamic> toJson({ValueSerializer? serializer}) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return <String, dynamic>{
      'id': serializer.toJson<String>(id),
      'productId': serializer.toJson<String>(productId),
      'saleId': serializer.toJson<String?>(saleId),
      'locationId': serializer.toJson<String?>(locationId),
      'transferId': serializer.toJson<String?>(transferId),
      'sourceMovementId': serializer.toJson<String?>(sourceMovementId),
      'type': serializer.toJson<String>(type),
      'qtyMilli': serializer.toJson<int>(qtyMilli),
      'unitPriceCents': serializer.toJson<int>(unitPriceCents),
      'tracksStock': serializer.toJson<bool>(tracksStock),
      'note': serializer.toJson<String?>(note),
      'createdAtMs': serializer.toJson<int>(createdAtMs),
    };
  }

  Movement copyWith({
    String? id,
    String? productId,
    Value<String?> saleId = const Value.absent(),
    Value<String?> locationId = const Value.absent(),
    Value<String?> transferId = const Value.absent(),
    Value<String?> sourceMovementId = const Value.absent(),
    String? type,
    int? qtyMilli,
    int? unitPriceCents,
    bool? tracksStock,
    Value<String?> note = const Value.absent(),
    int? createdAtMs,
  }) => Movement(
    id: id ?? this.id,
    productId: productId ?? this.productId,
    saleId: saleId.present ? saleId.value : this.saleId,
    locationId: locationId.present ? locationId.value : this.locationId,
    transferId: transferId.present ? transferId.value : this.transferId,
    sourceMovementId: sourceMovementId.present
        ? sourceMovementId.value
        : this.sourceMovementId,
    type: type ?? this.type,
    qtyMilli: qtyMilli ?? this.qtyMilli,
    unitPriceCents: unitPriceCents ?? this.unitPriceCents,
    tracksStock: tracksStock ?? this.tracksStock,
    note: note.present ? note.value : this.note,
    createdAtMs: createdAtMs ?? this.createdAtMs,
  );
  Movement copyWithCompanion(MovementsCompanion data) {
    return Movement(
      id: data.id.present ? data.id.value : this.id,
      productId: data.productId.present ? data.productId.value : this.productId,
      saleId: data.saleId.present ? data.saleId.value : this.saleId,
      locationId: data.locationId.present
          ? data.locationId.value
          : this.locationId,
      transferId: data.transferId.present
          ? data.transferId.value
          : this.transferId,
      sourceMovementId: data.sourceMovementId.present
          ? data.sourceMovementId.value
          : this.sourceMovementId,
      type: data.type.present ? data.type.value : this.type,
      qtyMilli: data.qtyMilli.present ? data.qtyMilli.value : this.qtyMilli,
      unitPriceCents: data.unitPriceCents.present
          ? data.unitPriceCents.value
          : this.unitPriceCents,
      tracksStock: data.tracksStock.present
          ? data.tracksStock.value
          : this.tracksStock,
      note: data.note.present ? data.note.value : this.note,
      createdAtMs: data.createdAtMs.present
          ? data.createdAtMs.value
          : this.createdAtMs,
    );
  }

  @override
  String toString() {
    return (StringBuffer('Movement(')
          ..write('id: $id, ')
          ..write('productId: $productId, ')
          ..write('saleId: $saleId, ')
          ..write('locationId: $locationId, ')
          ..write('transferId: $transferId, ')
          ..write('sourceMovementId: $sourceMovementId, ')
          ..write('type: $type, ')
          ..write('qtyMilli: $qtyMilli, ')
          ..write('unitPriceCents: $unitPriceCents, ')
          ..write('tracksStock: $tracksStock, ')
          ..write('note: $note, ')
          ..write('createdAtMs: $createdAtMs')
          ..write(')'))
        .toString();
  }

  @override
  int get hashCode => Object.hash(
    id,
    productId,
    saleId,
    locationId,
    transferId,
    sourceMovementId,
    type,
    qtyMilli,
    unitPriceCents,
    tracksStock,
    note,
    createdAtMs,
  );
  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      (other is Movement &&
          other.id == this.id &&
          other.productId == this.productId &&
          other.saleId == this.saleId &&
          other.locationId == this.locationId &&
          other.transferId == this.transferId &&
          other.sourceMovementId == this.sourceMovementId &&
          other.type == this.type &&
          other.qtyMilli == this.qtyMilli &&
          other.unitPriceCents == this.unitPriceCents &&
          other.tracksStock == this.tracksStock &&
          other.note == this.note &&
          other.createdAtMs == this.createdAtMs);
}

class MovementsCompanion extends UpdateCompanion<Movement> {
  final Value<String> id;
  final Value<String> productId;
  final Value<String?> saleId;
  final Value<String?> locationId;
  final Value<String?> transferId;
  final Value<String?> sourceMovementId;
  final Value<String> type;
  final Value<int> qtyMilli;
  final Value<int> unitPriceCents;
  final Value<bool> tracksStock;
  final Value<String?> note;
  final Value<int> createdAtMs;
  final Value<int> rowid;
  const MovementsCompanion({
    this.id = const Value.absent(),
    this.productId = const Value.absent(),
    this.saleId = const Value.absent(),
    this.locationId = const Value.absent(),
    this.transferId = const Value.absent(),
    this.sourceMovementId = const Value.absent(),
    this.type = const Value.absent(),
    this.qtyMilli = const Value.absent(),
    this.unitPriceCents = const Value.absent(),
    this.tracksStock = const Value.absent(),
    this.note = const Value.absent(),
    this.createdAtMs = const Value.absent(),
    this.rowid = const Value.absent(),
  });
  MovementsCompanion.insert({
    required String id,
    required String productId,
    this.saleId = const Value.absent(),
    this.locationId = const Value.absent(),
    this.transferId = const Value.absent(),
    this.sourceMovementId = const Value.absent(),
    required String type,
    required int qtyMilli,
    this.unitPriceCents = const Value.absent(),
    this.tracksStock = const Value.absent(),
    this.note = const Value.absent(),
    required int createdAtMs,
    this.rowid = const Value.absent(),
  }) : id = Value(id),
       productId = Value(productId),
       type = Value(type),
       qtyMilli = Value(qtyMilli),
       createdAtMs = Value(createdAtMs);
  static Insertable<Movement> custom({
    Expression<String>? id,
    Expression<String>? productId,
    Expression<String>? saleId,
    Expression<String>? locationId,
    Expression<String>? transferId,
    Expression<String>? sourceMovementId,
    Expression<String>? type,
    Expression<int>? qtyMilli,
    Expression<int>? unitPriceCents,
    Expression<bool>? tracksStock,
    Expression<String>? note,
    Expression<int>? createdAtMs,
    Expression<int>? rowid,
  }) {
    return RawValuesInsertable({
      if (id != null) 'id': id,
      if (productId != null) 'product_id': productId,
      if (saleId != null) 'sale_id': saleId,
      if (locationId != null) 'location_id': locationId,
      if (transferId != null) 'transfer_id': transferId,
      if (sourceMovementId != null) 'source_movement_id': sourceMovementId,
      if (type != null) 'type': type,
      if (qtyMilli != null) 'qty_milli': qtyMilli,
      if (unitPriceCents != null) 'unit_price_cents': unitPriceCents,
      if (tracksStock != null) 'tracks_stock': tracksStock,
      if (note != null) 'note': note,
      if (createdAtMs != null) 'created_at_ms': createdAtMs,
      if (rowid != null) 'rowid': rowid,
    });
  }

  MovementsCompanion copyWith({
    Value<String>? id,
    Value<String>? productId,
    Value<String?>? saleId,
    Value<String?>? locationId,
    Value<String?>? transferId,
    Value<String?>? sourceMovementId,
    Value<String>? type,
    Value<int>? qtyMilli,
    Value<int>? unitPriceCents,
    Value<bool>? tracksStock,
    Value<String?>? note,
    Value<int>? createdAtMs,
    Value<int>? rowid,
  }) {
    return MovementsCompanion(
      id: id ?? this.id,
      productId: productId ?? this.productId,
      saleId: saleId ?? this.saleId,
      locationId: locationId ?? this.locationId,
      transferId: transferId ?? this.transferId,
      sourceMovementId: sourceMovementId ?? this.sourceMovementId,
      type: type ?? this.type,
      qtyMilli: qtyMilli ?? this.qtyMilli,
      unitPriceCents: unitPriceCents ?? this.unitPriceCents,
      tracksStock: tracksStock ?? this.tracksStock,
      note: note ?? this.note,
      createdAtMs: createdAtMs ?? this.createdAtMs,
      rowid: rowid ?? this.rowid,
    );
  }

  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    if (id.present) {
      map['id'] = Variable<String>(id.value);
    }
    if (productId.present) {
      map['product_id'] = Variable<String>(productId.value);
    }
    if (saleId.present) {
      map['sale_id'] = Variable<String>(saleId.value);
    }
    if (locationId.present) {
      map['location_id'] = Variable<String>(locationId.value);
    }
    if (transferId.present) {
      map['transfer_id'] = Variable<String>(transferId.value);
    }
    if (sourceMovementId.present) {
      map['source_movement_id'] = Variable<String>(sourceMovementId.value);
    }
    if (type.present) {
      map['type'] = Variable<String>(type.value);
    }
    if (qtyMilli.present) {
      map['qty_milli'] = Variable<int>(qtyMilli.value);
    }
    if (unitPriceCents.present) {
      map['unit_price_cents'] = Variable<int>(unitPriceCents.value);
    }
    if (tracksStock.present) {
      map['tracks_stock'] = Variable<bool>(tracksStock.value);
    }
    if (note.present) {
      map['note'] = Variable<String>(note.value);
    }
    if (createdAtMs.present) {
      map['created_at_ms'] = Variable<int>(createdAtMs.value);
    }
    if (rowid.present) {
      map['rowid'] = Variable<int>(rowid.value);
    }
    return map;
  }

  @override
  String toString() {
    return (StringBuffer('MovementsCompanion(')
          ..write('id: $id, ')
          ..write('productId: $productId, ')
          ..write('saleId: $saleId, ')
          ..write('locationId: $locationId, ')
          ..write('transferId: $transferId, ')
          ..write('sourceMovementId: $sourceMovementId, ')
          ..write('type: $type, ')
          ..write('qtyMilli: $qtyMilli, ')
          ..write('unitPriceCents: $unitPriceCents, ')
          ..write('tracksStock: $tracksStock, ')
          ..write('note: $note, ')
          ..write('createdAtMs: $createdAtMs, ')
          ..write('rowid: $rowid')
          ..write(')'))
        .toString();
  }
}

class $OutboxEntriesTable extends OutboxEntries
    with TableInfo<$OutboxEntriesTable, OutboxEntry> {
  @override
  final GeneratedDatabase attachedDatabase;
  final String? _alias;
  $OutboxEntriesTable(this.attachedDatabase, [this._alias]);
  static const VerificationMeta _idMeta = const VerificationMeta('id');
  @override
  late final GeneratedColumn<String> id = GeneratedColumn<String>(
    'id',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _entityMeta = const VerificationMeta('entity');
  @override
  late final GeneratedColumn<String> entity = GeneratedColumn<String>(
    'entity',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _entityIdMeta = const VerificationMeta(
    'entityId',
  );
  @override
  late final GeneratedColumn<String> entityId = GeneratedColumn<String>(
    'entity_id',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _opMeta = const VerificationMeta('op');
  @override
  late final GeneratedColumn<String> op = GeneratedColumn<String>(
    'op',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _payloadJsonMeta = const VerificationMeta(
    'payloadJson',
  );
  @override
  late final GeneratedColumn<String> payloadJson = GeneratedColumn<String>(
    'payload_json',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _clientTsMeta = const VerificationMeta(
    'clientTs',
  );
  @override
  late final GeneratedColumn<int> clientTs = GeneratedColumn<int>(
    'client_ts',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _statusMeta = const VerificationMeta('status');
  @override
  late final GeneratedColumn<String> status = GeneratedColumn<String>(
    'status',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
    defaultValue: const Constant('pending'),
  );
  static const VerificationMeta _retryCountMeta = const VerificationMeta(
    'retryCount',
  );
  @override
  late final GeneratedColumn<int> retryCount = GeneratedColumn<int>(
    'retry_count',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
    defaultValue: const Constant(0),
  );
  static const VerificationMeta _errorReasonMeta = const VerificationMeta(
    'errorReason',
  );
  @override
  late final GeneratedColumn<String> errorReason = GeneratedColumn<String>(
    'error_reason',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _createdAtMeta = const VerificationMeta(
    'createdAt',
  );
  @override
  late final GeneratedColumn<int> createdAt = GeneratedColumn<int>(
    'created_at',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: true,
  );
  @override
  List<GeneratedColumn> get $columns => [
    id,
    entity,
    entityId,
    op,
    payloadJson,
    clientTs,
    status,
    retryCount,
    errorReason,
    createdAt,
  ];
  @override
  String get aliasedName => _alias ?? actualTableName;
  @override
  String get actualTableName => $name;
  static const String $name = 'outbox_entries';
  @override
  VerificationContext validateIntegrity(
    Insertable<OutboxEntry> instance, {
    bool isInserting = false,
  }) {
    final context = VerificationContext();
    final data = instance.toColumns(true);
    if (data.containsKey('id')) {
      context.handle(_idMeta, id.isAcceptableOrUnknown(data['id']!, _idMeta));
    } else if (isInserting) {
      context.missing(_idMeta);
    }
    if (data.containsKey('entity')) {
      context.handle(
        _entityMeta,
        entity.isAcceptableOrUnknown(data['entity']!, _entityMeta),
      );
    } else if (isInserting) {
      context.missing(_entityMeta);
    }
    if (data.containsKey('entity_id')) {
      context.handle(
        _entityIdMeta,
        entityId.isAcceptableOrUnknown(data['entity_id']!, _entityIdMeta),
      );
    } else if (isInserting) {
      context.missing(_entityIdMeta);
    }
    if (data.containsKey('op')) {
      context.handle(_opMeta, op.isAcceptableOrUnknown(data['op']!, _opMeta));
    } else if (isInserting) {
      context.missing(_opMeta);
    }
    if (data.containsKey('payload_json')) {
      context.handle(
        _payloadJsonMeta,
        payloadJson.isAcceptableOrUnknown(
          data['payload_json']!,
          _payloadJsonMeta,
        ),
      );
    } else if (isInserting) {
      context.missing(_payloadJsonMeta);
    }
    if (data.containsKey('client_ts')) {
      context.handle(
        _clientTsMeta,
        clientTs.isAcceptableOrUnknown(data['client_ts']!, _clientTsMeta),
      );
    } else if (isInserting) {
      context.missing(_clientTsMeta);
    }
    if (data.containsKey('status')) {
      context.handle(
        _statusMeta,
        status.isAcceptableOrUnknown(data['status']!, _statusMeta),
      );
    }
    if (data.containsKey('retry_count')) {
      context.handle(
        _retryCountMeta,
        retryCount.isAcceptableOrUnknown(data['retry_count']!, _retryCountMeta),
      );
    }
    if (data.containsKey('error_reason')) {
      context.handle(
        _errorReasonMeta,
        errorReason.isAcceptableOrUnknown(
          data['error_reason']!,
          _errorReasonMeta,
        ),
      );
    }
    if (data.containsKey('created_at')) {
      context.handle(
        _createdAtMeta,
        createdAt.isAcceptableOrUnknown(data['created_at']!, _createdAtMeta),
      );
    } else if (isInserting) {
      context.missing(_createdAtMeta);
    }
    return context;
  }

  @override
  Set<GeneratedColumn> get $primaryKey => {id};
  @override
  OutboxEntry map(Map<String, dynamic> data, {String? tablePrefix}) {
    final effectivePrefix = tablePrefix != null ? '$tablePrefix.' : '';
    return OutboxEntry(
      id: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}id'],
      )!,
      entity: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}entity'],
      )!,
      entityId: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}entity_id'],
      )!,
      op: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}op'],
      )!,
      payloadJson: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}payload_json'],
      )!,
      clientTs: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}client_ts'],
      )!,
      status: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}status'],
      )!,
      retryCount: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}retry_count'],
      )!,
      errorReason: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}error_reason'],
      ),
      createdAt: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}created_at'],
      )!,
    );
  }

  @override
  $OutboxEntriesTable createAlias(String alias) {
    return $OutboxEntriesTable(attachedDatabase, alias);
  }
}

class OutboxEntry extends DataClass implements Insertable<OutboxEntry> {
  final String id;
  final String entity;
  final String entityId;
  final String op;
  final String payloadJson;
  final int clientTs;
  final String status;
  final int retryCount;
  final String? errorReason;
  final int createdAt;
  const OutboxEntry({
    required this.id,
    required this.entity,
    required this.entityId,
    required this.op,
    required this.payloadJson,
    required this.clientTs,
    required this.status,
    required this.retryCount,
    this.errorReason,
    required this.createdAt,
  });
  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    map['id'] = Variable<String>(id);
    map['entity'] = Variable<String>(entity);
    map['entity_id'] = Variable<String>(entityId);
    map['op'] = Variable<String>(op);
    map['payload_json'] = Variable<String>(payloadJson);
    map['client_ts'] = Variable<int>(clientTs);
    map['status'] = Variable<String>(status);
    map['retry_count'] = Variable<int>(retryCount);
    if (!nullToAbsent || errorReason != null) {
      map['error_reason'] = Variable<String>(errorReason);
    }
    map['created_at'] = Variable<int>(createdAt);
    return map;
  }

  OutboxEntriesCompanion toCompanion(bool nullToAbsent) {
    return OutboxEntriesCompanion(
      id: Value(id),
      entity: Value(entity),
      entityId: Value(entityId),
      op: Value(op),
      payloadJson: Value(payloadJson),
      clientTs: Value(clientTs),
      status: Value(status),
      retryCount: Value(retryCount),
      errorReason: errorReason == null && nullToAbsent
          ? const Value.absent()
          : Value(errorReason),
      createdAt: Value(createdAt),
    );
  }

  factory OutboxEntry.fromJson(
    Map<String, dynamic> json, {
    ValueSerializer? serializer,
  }) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return OutboxEntry(
      id: serializer.fromJson<String>(json['id']),
      entity: serializer.fromJson<String>(json['entity']),
      entityId: serializer.fromJson<String>(json['entityId']),
      op: serializer.fromJson<String>(json['op']),
      payloadJson: serializer.fromJson<String>(json['payloadJson']),
      clientTs: serializer.fromJson<int>(json['clientTs']),
      status: serializer.fromJson<String>(json['status']),
      retryCount: serializer.fromJson<int>(json['retryCount']),
      errorReason: serializer.fromJson<String?>(json['errorReason']),
      createdAt: serializer.fromJson<int>(json['createdAt']),
    );
  }
  @override
  Map<String, dynamic> toJson({ValueSerializer? serializer}) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return <String, dynamic>{
      'id': serializer.toJson<String>(id),
      'entity': serializer.toJson<String>(entity),
      'entityId': serializer.toJson<String>(entityId),
      'op': serializer.toJson<String>(op),
      'payloadJson': serializer.toJson<String>(payloadJson),
      'clientTs': serializer.toJson<int>(clientTs),
      'status': serializer.toJson<String>(status),
      'retryCount': serializer.toJson<int>(retryCount),
      'errorReason': serializer.toJson<String?>(errorReason),
      'createdAt': serializer.toJson<int>(createdAt),
    };
  }

  OutboxEntry copyWith({
    String? id,
    String? entity,
    String? entityId,
    String? op,
    String? payloadJson,
    int? clientTs,
    String? status,
    int? retryCount,
    Value<String?> errorReason = const Value.absent(),
    int? createdAt,
  }) => OutboxEntry(
    id: id ?? this.id,
    entity: entity ?? this.entity,
    entityId: entityId ?? this.entityId,
    op: op ?? this.op,
    payloadJson: payloadJson ?? this.payloadJson,
    clientTs: clientTs ?? this.clientTs,
    status: status ?? this.status,
    retryCount: retryCount ?? this.retryCount,
    errorReason: errorReason.present ? errorReason.value : this.errorReason,
    createdAt: createdAt ?? this.createdAt,
  );
  OutboxEntry copyWithCompanion(OutboxEntriesCompanion data) {
    return OutboxEntry(
      id: data.id.present ? data.id.value : this.id,
      entity: data.entity.present ? data.entity.value : this.entity,
      entityId: data.entityId.present ? data.entityId.value : this.entityId,
      op: data.op.present ? data.op.value : this.op,
      payloadJson: data.payloadJson.present
          ? data.payloadJson.value
          : this.payloadJson,
      clientTs: data.clientTs.present ? data.clientTs.value : this.clientTs,
      status: data.status.present ? data.status.value : this.status,
      retryCount: data.retryCount.present
          ? data.retryCount.value
          : this.retryCount,
      errorReason: data.errorReason.present
          ? data.errorReason.value
          : this.errorReason,
      createdAt: data.createdAt.present ? data.createdAt.value : this.createdAt,
    );
  }

  @override
  String toString() {
    return (StringBuffer('OutboxEntry(')
          ..write('id: $id, ')
          ..write('entity: $entity, ')
          ..write('entityId: $entityId, ')
          ..write('op: $op, ')
          ..write('payloadJson: $payloadJson, ')
          ..write('clientTs: $clientTs, ')
          ..write('status: $status, ')
          ..write('retryCount: $retryCount, ')
          ..write('errorReason: $errorReason, ')
          ..write('createdAt: $createdAt')
          ..write(')'))
        .toString();
  }

  @override
  int get hashCode => Object.hash(
    id,
    entity,
    entityId,
    op,
    payloadJson,
    clientTs,
    status,
    retryCount,
    errorReason,
    createdAt,
  );
  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      (other is OutboxEntry &&
          other.id == this.id &&
          other.entity == this.entity &&
          other.entityId == this.entityId &&
          other.op == this.op &&
          other.payloadJson == this.payloadJson &&
          other.clientTs == this.clientTs &&
          other.status == this.status &&
          other.retryCount == this.retryCount &&
          other.errorReason == this.errorReason &&
          other.createdAt == this.createdAt);
}

class OutboxEntriesCompanion extends UpdateCompanion<OutboxEntry> {
  final Value<String> id;
  final Value<String> entity;
  final Value<String> entityId;
  final Value<String> op;
  final Value<String> payloadJson;
  final Value<int> clientTs;
  final Value<String> status;
  final Value<int> retryCount;
  final Value<String?> errorReason;
  final Value<int> createdAt;
  final Value<int> rowid;
  const OutboxEntriesCompanion({
    this.id = const Value.absent(),
    this.entity = const Value.absent(),
    this.entityId = const Value.absent(),
    this.op = const Value.absent(),
    this.payloadJson = const Value.absent(),
    this.clientTs = const Value.absent(),
    this.status = const Value.absent(),
    this.retryCount = const Value.absent(),
    this.errorReason = const Value.absent(),
    this.createdAt = const Value.absent(),
    this.rowid = const Value.absent(),
  });
  OutboxEntriesCompanion.insert({
    required String id,
    required String entity,
    required String entityId,
    required String op,
    required String payloadJson,
    required int clientTs,
    this.status = const Value.absent(),
    this.retryCount = const Value.absent(),
    this.errorReason = const Value.absent(),
    required int createdAt,
    this.rowid = const Value.absent(),
  }) : id = Value(id),
       entity = Value(entity),
       entityId = Value(entityId),
       op = Value(op),
       payloadJson = Value(payloadJson),
       clientTs = Value(clientTs),
       createdAt = Value(createdAt);
  static Insertable<OutboxEntry> custom({
    Expression<String>? id,
    Expression<String>? entity,
    Expression<String>? entityId,
    Expression<String>? op,
    Expression<String>? payloadJson,
    Expression<int>? clientTs,
    Expression<String>? status,
    Expression<int>? retryCount,
    Expression<String>? errorReason,
    Expression<int>? createdAt,
    Expression<int>? rowid,
  }) {
    return RawValuesInsertable({
      if (id != null) 'id': id,
      if (entity != null) 'entity': entity,
      if (entityId != null) 'entity_id': entityId,
      if (op != null) 'op': op,
      if (payloadJson != null) 'payload_json': payloadJson,
      if (clientTs != null) 'client_ts': clientTs,
      if (status != null) 'status': status,
      if (retryCount != null) 'retry_count': retryCount,
      if (errorReason != null) 'error_reason': errorReason,
      if (createdAt != null) 'created_at': createdAt,
      if (rowid != null) 'rowid': rowid,
    });
  }

  OutboxEntriesCompanion copyWith({
    Value<String>? id,
    Value<String>? entity,
    Value<String>? entityId,
    Value<String>? op,
    Value<String>? payloadJson,
    Value<int>? clientTs,
    Value<String>? status,
    Value<int>? retryCount,
    Value<String?>? errorReason,
    Value<int>? createdAt,
    Value<int>? rowid,
  }) {
    return OutboxEntriesCompanion(
      id: id ?? this.id,
      entity: entity ?? this.entity,
      entityId: entityId ?? this.entityId,
      op: op ?? this.op,
      payloadJson: payloadJson ?? this.payloadJson,
      clientTs: clientTs ?? this.clientTs,
      status: status ?? this.status,
      retryCount: retryCount ?? this.retryCount,
      errorReason: errorReason ?? this.errorReason,
      createdAt: createdAt ?? this.createdAt,
      rowid: rowid ?? this.rowid,
    );
  }

  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    if (id.present) {
      map['id'] = Variable<String>(id.value);
    }
    if (entity.present) {
      map['entity'] = Variable<String>(entity.value);
    }
    if (entityId.present) {
      map['entity_id'] = Variable<String>(entityId.value);
    }
    if (op.present) {
      map['op'] = Variable<String>(op.value);
    }
    if (payloadJson.present) {
      map['payload_json'] = Variable<String>(payloadJson.value);
    }
    if (clientTs.present) {
      map['client_ts'] = Variable<int>(clientTs.value);
    }
    if (status.present) {
      map['status'] = Variable<String>(status.value);
    }
    if (retryCount.present) {
      map['retry_count'] = Variable<int>(retryCount.value);
    }
    if (errorReason.present) {
      map['error_reason'] = Variable<String>(errorReason.value);
    }
    if (createdAt.present) {
      map['created_at'] = Variable<int>(createdAt.value);
    }
    if (rowid.present) {
      map['rowid'] = Variable<int>(rowid.value);
    }
    return map;
  }

  @override
  String toString() {
    return (StringBuffer('OutboxEntriesCompanion(')
          ..write('id: $id, ')
          ..write('entity: $entity, ')
          ..write('entityId: $entityId, ')
          ..write('op: $op, ')
          ..write('payloadJson: $payloadJson, ')
          ..write('clientTs: $clientTs, ')
          ..write('status: $status, ')
          ..write('retryCount: $retryCount, ')
          ..write('errorReason: $errorReason, ')
          ..write('createdAt: $createdAt, ')
          ..write('rowid: $rowid')
          ..write(')'))
        .toString();
  }
}

class $SyncMetaEntriesTable extends SyncMetaEntries
    with TableInfo<$SyncMetaEntriesTable, SyncMetaEntry> {
  @override
  final GeneratedDatabase attachedDatabase;
  final String? _alias;
  $SyncMetaEntriesTable(this.attachedDatabase, [this._alias]);
  static const VerificationMeta _keyMeta = const VerificationMeta('key');
  @override
  late final GeneratedColumn<String> key = GeneratedColumn<String>(
    'key',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _valueMeta = const VerificationMeta('value');
  @override
  late final GeneratedColumn<String> value = GeneratedColumn<String>(
    'value',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  @override
  List<GeneratedColumn> get $columns => [key, value];
  @override
  String get aliasedName => _alias ?? actualTableName;
  @override
  String get actualTableName => $name;
  static const String $name = 'sync_meta_entries';
  @override
  VerificationContext validateIntegrity(
    Insertable<SyncMetaEntry> instance, {
    bool isInserting = false,
  }) {
    final context = VerificationContext();
    final data = instance.toColumns(true);
    if (data.containsKey('key')) {
      context.handle(
        _keyMeta,
        key.isAcceptableOrUnknown(data['key']!, _keyMeta),
      );
    } else if (isInserting) {
      context.missing(_keyMeta);
    }
    if (data.containsKey('value')) {
      context.handle(
        _valueMeta,
        value.isAcceptableOrUnknown(data['value']!, _valueMeta),
      );
    } else if (isInserting) {
      context.missing(_valueMeta);
    }
    return context;
  }

  @override
  Set<GeneratedColumn> get $primaryKey => {key};
  @override
  SyncMetaEntry map(Map<String, dynamic> data, {String? tablePrefix}) {
    final effectivePrefix = tablePrefix != null ? '$tablePrefix.' : '';
    return SyncMetaEntry(
      key: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}key'],
      )!,
      value: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}value'],
      )!,
    );
  }

  @override
  $SyncMetaEntriesTable createAlias(String alias) {
    return $SyncMetaEntriesTable(attachedDatabase, alias);
  }
}

class SyncMetaEntry extends DataClass implements Insertable<SyncMetaEntry> {
  final String key;
  final String value;
  const SyncMetaEntry({required this.key, required this.value});
  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    map['key'] = Variable<String>(key);
    map['value'] = Variable<String>(value);
    return map;
  }

  SyncMetaEntriesCompanion toCompanion(bool nullToAbsent) {
    return SyncMetaEntriesCompanion(key: Value(key), value: Value(value));
  }

  factory SyncMetaEntry.fromJson(
    Map<String, dynamic> json, {
    ValueSerializer? serializer,
  }) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return SyncMetaEntry(
      key: serializer.fromJson<String>(json['key']),
      value: serializer.fromJson<String>(json['value']),
    );
  }
  @override
  Map<String, dynamic> toJson({ValueSerializer? serializer}) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return <String, dynamic>{
      'key': serializer.toJson<String>(key),
      'value': serializer.toJson<String>(value),
    };
  }

  SyncMetaEntry copyWith({String? key, String? value}) =>
      SyncMetaEntry(key: key ?? this.key, value: value ?? this.value);
  SyncMetaEntry copyWithCompanion(SyncMetaEntriesCompanion data) {
    return SyncMetaEntry(
      key: data.key.present ? data.key.value : this.key,
      value: data.value.present ? data.value.value : this.value,
    );
  }

  @override
  String toString() {
    return (StringBuffer('SyncMetaEntry(')
          ..write('key: $key, ')
          ..write('value: $value')
          ..write(')'))
        .toString();
  }

  @override
  int get hashCode => Object.hash(key, value);
  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      (other is SyncMetaEntry &&
          other.key == this.key &&
          other.value == this.value);
}

class SyncMetaEntriesCompanion extends UpdateCompanion<SyncMetaEntry> {
  final Value<String> key;
  final Value<String> value;
  final Value<int> rowid;
  const SyncMetaEntriesCompanion({
    this.key = const Value.absent(),
    this.value = const Value.absent(),
    this.rowid = const Value.absent(),
  });
  SyncMetaEntriesCompanion.insert({
    required String key,
    required String value,
    this.rowid = const Value.absent(),
  }) : key = Value(key),
       value = Value(value);
  static Insertable<SyncMetaEntry> custom({
    Expression<String>? key,
    Expression<String>? value,
    Expression<int>? rowid,
  }) {
    return RawValuesInsertable({
      if (key != null) 'key': key,
      if (value != null) 'value': value,
      if (rowid != null) 'rowid': rowid,
    });
  }

  SyncMetaEntriesCompanion copyWith({
    Value<String>? key,
    Value<String>? value,
    Value<int>? rowid,
  }) {
    return SyncMetaEntriesCompanion(
      key: key ?? this.key,
      value: value ?? this.value,
      rowid: rowid ?? this.rowid,
    );
  }

  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    if (key.present) {
      map['key'] = Variable<String>(key.value);
    }
    if (value.present) {
      map['value'] = Variable<String>(value.value);
    }
    if (rowid.present) {
      map['rowid'] = Variable<int>(rowid.value);
    }
    return map;
  }

  @override
  String toString() {
    return (StringBuffer('SyncMetaEntriesCompanion(')
          ..write('key: $key, ')
          ..write('value: $value, ')
          ..write('rowid: $rowid')
          ..write(')'))
        .toString();
  }
}

class $AppSettingsTable extends AppSettings
    with TableInfo<$AppSettingsTable, AppSetting> {
  @override
  final GeneratedDatabase attachedDatabase;
  final String? _alias;
  $AppSettingsTable(this.attachedDatabase, [this._alias]);
  static const VerificationMeta _keyMeta = const VerificationMeta('key');
  @override
  late final GeneratedColumn<String> key = GeneratedColumn<String>(
    'key',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _valueMeta = const VerificationMeta('value');
  @override
  late final GeneratedColumn<String> value = GeneratedColumn<String>(
    'value',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  @override
  List<GeneratedColumn> get $columns => [key, value];
  @override
  String get aliasedName => _alias ?? actualTableName;
  @override
  String get actualTableName => $name;
  static const String $name = 'app_settings';
  @override
  VerificationContext validateIntegrity(
    Insertable<AppSetting> instance, {
    bool isInserting = false,
  }) {
    final context = VerificationContext();
    final data = instance.toColumns(true);
    if (data.containsKey('key')) {
      context.handle(
        _keyMeta,
        key.isAcceptableOrUnknown(data['key']!, _keyMeta),
      );
    } else if (isInserting) {
      context.missing(_keyMeta);
    }
    if (data.containsKey('value')) {
      context.handle(
        _valueMeta,
        value.isAcceptableOrUnknown(data['value']!, _valueMeta),
      );
    } else if (isInserting) {
      context.missing(_valueMeta);
    }
    return context;
  }

  @override
  Set<GeneratedColumn> get $primaryKey => {key};
  @override
  AppSetting map(Map<String, dynamic> data, {String? tablePrefix}) {
    final effectivePrefix = tablePrefix != null ? '$tablePrefix.' : '';
    return AppSetting(
      key: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}key'],
      )!,
      value: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}value'],
      )!,
    );
  }

  @override
  $AppSettingsTable createAlias(String alias) {
    return $AppSettingsTable(attachedDatabase, alias);
  }
}

class AppSetting extends DataClass implements Insertable<AppSetting> {
  final String key;
  final String value;
  const AppSetting({required this.key, required this.value});
  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    map['key'] = Variable<String>(key);
    map['value'] = Variable<String>(value);
    return map;
  }

  AppSettingsCompanion toCompanion(bool nullToAbsent) {
    return AppSettingsCompanion(key: Value(key), value: Value(value));
  }

  factory AppSetting.fromJson(
    Map<String, dynamic> json, {
    ValueSerializer? serializer,
  }) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return AppSetting(
      key: serializer.fromJson<String>(json['key']),
      value: serializer.fromJson<String>(json['value']),
    );
  }
  @override
  Map<String, dynamic> toJson({ValueSerializer? serializer}) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return <String, dynamic>{
      'key': serializer.toJson<String>(key),
      'value': serializer.toJson<String>(value),
    };
  }

  AppSetting copyWith({String? key, String? value}) =>
      AppSetting(key: key ?? this.key, value: value ?? this.value);
  AppSetting copyWithCompanion(AppSettingsCompanion data) {
    return AppSetting(
      key: data.key.present ? data.key.value : this.key,
      value: data.value.present ? data.value.value : this.value,
    );
  }

  @override
  String toString() {
    return (StringBuffer('AppSetting(')
          ..write('key: $key, ')
          ..write('value: $value')
          ..write(')'))
        .toString();
  }

  @override
  int get hashCode => Object.hash(key, value);
  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      (other is AppSetting &&
          other.key == this.key &&
          other.value == this.value);
}

class AppSettingsCompanion extends UpdateCompanion<AppSetting> {
  final Value<String> key;
  final Value<String> value;
  final Value<int> rowid;
  const AppSettingsCompanion({
    this.key = const Value.absent(),
    this.value = const Value.absent(),
    this.rowid = const Value.absent(),
  });
  AppSettingsCompanion.insert({
    required String key,
    required String value,
    this.rowid = const Value.absent(),
  }) : key = Value(key),
       value = Value(value);
  static Insertable<AppSetting> custom({
    Expression<String>? key,
    Expression<String>? value,
    Expression<int>? rowid,
  }) {
    return RawValuesInsertable({
      if (key != null) 'key': key,
      if (value != null) 'value': value,
      if (rowid != null) 'rowid': rowid,
    });
  }

  AppSettingsCompanion copyWith({
    Value<String>? key,
    Value<String>? value,
    Value<int>? rowid,
  }) {
    return AppSettingsCompanion(
      key: key ?? this.key,
      value: value ?? this.value,
      rowid: rowid ?? this.rowid,
    );
  }

  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    if (key.present) {
      map['key'] = Variable<String>(key.value);
    }
    if (value.present) {
      map['value'] = Variable<String>(value.value);
    }
    if (rowid.present) {
      map['rowid'] = Variable<int>(rowid.value);
    }
    return map;
  }

  @override
  String toString() {
    return (StringBuffer('AppSettingsCompanion(')
          ..write('key: $key, ')
          ..write('value: $value, ')
          ..write('rowid: $rowid')
          ..write(')'))
        .toString();
  }
}

class $LocationsTable extends Locations
    with TableInfo<$LocationsTable, Location> {
  @override
  final GeneratedDatabase attachedDatabase;
  final String? _alias;
  $LocationsTable(this.attachedDatabase, [this._alias]);
  static const VerificationMeta _idMeta = const VerificationMeta('id');
  @override
  late final GeneratedColumn<String> id = GeneratedColumn<String>(
    'id',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _nameMeta = const VerificationMeta('name');
  @override
  late final GeneratedColumn<String> name = GeneratedColumn<String>(
    'name',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _kindMeta = const VerificationMeta('kind');
  @override
  late final GeneratedColumn<String> kind = GeneratedColumn<String>(
    'kind',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
    defaultValue: const Constant('store'),
  );
  static const VerificationMeta _isDefaultMeta = const VerificationMeta(
    'isDefault',
  );
  @override
  late final GeneratedColumn<bool> isDefault = GeneratedColumn<bool>(
    'is_default',
    aliasedName,
    false,
    type: DriftSqlType.bool,
    requiredDuringInsert: false,
    defaultConstraints: GeneratedColumn.constraintIsAlways(
      'CHECK ("is_default" IN (0, 1))',
    ),
    defaultValue: const Constant(false),
  );
  static const VerificationMeta _isActiveMeta = const VerificationMeta(
    'isActive',
  );
  @override
  late final GeneratedColumn<bool> isActive = GeneratedColumn<bool>(
    'is_active',
    aliasedName,
    false,
    type: DriftSqlType.bool,
    requiredDuringInsert: false,
    defaultConstraints: GeneratedColumn.constraintIsAlways(
      'CHECK ("is_active" IN (0, 1))',
    ),
    defaultValue: const Constant(true),
  );
  static const VerificationMeta _updatedAtMsMeta = const VerificationMeta(
    'updatedAtMs',
  );
  @override
  late final GeneratedColumn<int> updatedAtMs = GeneratedColumn<int>(
    'updated_at_ms',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: true,
  );
  @override
  List<GeneratedColumn> get $columns => [
    id,
    name,
    kind,
    isDefault,
    isActive,
    updatedAtMs,
  ];
  @override
  String get aliasedName => _alias ?? actualTableName;
  @override
  String get actualTableName => $name;
  static const String $name = 'locations';
  @override
  VerificationContext validateIntegrity(
    Insertable<Location> instance, {
    bool isInserting = false,
  }) {
    final context = VerificationContext();
    final data = instance.toColumns(true);
    if (data.containsKey('id')) {
      context.handle(_idMeta, id.isAcceptableOrUnknown(data['id']!, _idMeta));
    } else if (isInserting) {
      context.missing(_idMeta);
    }
    if (data.containsKey('name')) {
      context.handle(
        _nameMeta,
        name.isAcceptableOrUnknown(data['name']!, _nameMeta),
      );
    } else if (isInserting) {
      context.missing(_nameMeta);
    }
    if (data.containsKey('kind')) {
      context.handle(
        _kindMeta,
        kind.isAcceptableOrUnknown(data['kind']!, _kindMeta),
      );
    }
    if (data.containsKey('is_default')) {
      context.handle(
        _isDefaultMeta,
        isDefault.isAcceptableOrUnknown(data['is_default']!, _isDefaultMeta),
      );
    }
    if (data.containsKey('is_active')) {
      context.handle(
        _isActiveMeta,
        isActive.isAcceptableOrUnknown(data['is_active']!, _isActiveMeta),
      );
    }
    if (data.containsKey('updated_at_ms')) {
      context.handle(
        _updatedAtMsMeta,
        updatedAtMs.isAcceptableOrUnknown(
          data['updated_at_ms']!,
          _updatedAtMsMeta,
        ),
      );
    } else if (isInserting) {
      context.missing(_updatedAtMsMeta);
    }
    return context;
  }

  @override
  Set<GeneratedColumn> get $primaryKey => {id};
  @override
  Location map(Map<String, dynamic> data, {String? tablePrefix}) {
    final effectivePrefix = tablePrefix != null ? '$tablePrefix.' : '';
    return Location(
      id: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}id'],
      )!,
      name: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}name'],
      )!,
      kind: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}kind'],
      )!,
      isDefault: attachedDatabase.typeMapping.read(
        DriftSqlType.bool,
        data['${effectivePrefix}is_default'],
      )!,
      isActive: attachedDatabase.typeMapping.read(
        DriftSqlType.bool,
        data['${effectivePrefix}is_active'],
      )!,
      updatedAtMs: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}updated_at_ms'],
      )!,
    );
  }

  @override
  $LocationsTable createAlias(String alias) {
    return $LocationsTable(attachedDatabase, alias);
  }
}

class Location extends DataClass implements Insertable<Location> {
  final String id;
  final String name;
  final String kind;
  final bool isDefault;
  final bool isActive;
  final int updatedAtMs;
  const Location({
    required this.id,
    required this.name,
    required this.kind,
    required this.isDefault,
    required this.isActive,
    required this.updatedAtMs,
  });
  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    map['id'] = Variable<String>(id);
    map['name'] = Variable<String>(name);
    map['kind'] = Variable<String>(kind);
    map['is_default'] = Variable<bool>(isDefault);
    map['is_active'] = Variable<bool>(isActive);
    map['updated_at_ms'] = Variable<int>(updatedAtMs);
    return map;
  }

  LocationsCompanion toCompanion(bool nullToAbsent) {
    return LocationsCompanion(
      id: Value(id),
      name: Value(name),
      kind: Value(kind),
      isDefault: Value(isDefault),
      isActive: Value(isActive),
      updatedAtMs: Value(updatedAtMs),
    );
  }

  factory Location.fromJson(
    Map<String, dynamic> json, {
    ValueSerializer? serializer,
  }) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return Location(
      id: serializer.fromJson<String>(json['id']),
      name: serializer.fromJson<String>(json['name']),
      kind: serializer.fromJson<String>(json['kind']),
      isDefault: serializer.fromJson<bool>(json['isDefault']),
      isActive: serializer.fromJson<bool>(json['isActive']),
      updatedAtMs: serializer.fromJson<int>(json['updatedAtMs']),
    );
  }
  @override
  Map<String, dynamic> toJson({ValueSerializer? serializer}) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return <String, dynamic>{
      'id': serializer.toJson<String>(id),
      'name': serializer.toJson<String>(name),
      'kind': serializer.toJson<String>(kind),
      'isDefault': serializer.toJson<bool>(isDefault),
      'isActive': serializer.toJson<bool>(isActive),
      'updatedAtMs': serializer.toJson<int>(updatedAtMs),
    };
  }

  Location copyWith({
    String? id,
    String? name,
    String? kind,
    bool? isDefault,
    bool? isActive,
    int? updatedAtMs,
  }) => Location(
    id: id ?? this.id,
    name: name ?? this.name,
    kind: kind ?? this.kind,
    isDefault: isDefault ?? this.isDefault,
    isActive: isActive ?? this.isActive,
    updatedAtMs: updatedAtMs ?? this.updatedAtMs,
  );
  Location copyWithCompanion(LocationsCompanion data) {
    return Location(
      id: data.id.present ? data.id.value : this.id,
      name: data.name.present ? data.name.value : this.name,
      kind: data.kind.present ? data.kind.value : this.kind,
      isDefault: data.isDefault.present ? data.isDefault.value : this.isDefault,
      isActive: data.isActive.present ? data.isActive.value : this.isActive,
      updatedAtMs: data.updatedAtMs.present
          ? data.updatedAtMs.value
          : this.updatedAtMs,
    );
  }

  @override
  String toString() {
    return (StringBuffer('Location(')
          ..write('id: $id, ')
          ..write('name: $name, ')
          ..write('kind: $kind, ')
          ..write('isDefault: $isDefault, ')
          ..write('isActive: $isActive, ')
          ..write('updatedAtMs: $updatedAtMs')
          ..write(')'))
        .toString();
  }

  @override
  int get hashCode =>
      Object.hash(id, name, kind, isDefault, isActive, updatedAtMs);
  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      (other is Location &&
          other.id == this.id &&
          other.name == this.name &&
          other.kind == this.kind &&
          other.isDefault == this.isDefault &&
          other.isActive == this.isActive &&
          other.updatedAtMs == this.updatedAtMs);
}

class LocationsCompanion extends UpdateCompanion<Location> {
  final Value<String> id;
  final Value<String> name;
  final Value<String> kind;
  final Value<bool> isDefault;
  final Value<bool> isActive;
  final Value<int> updatedAtMs;
  final Value<int> rowid;
  const LocationsCompanion({
    this.id = const Value.absent(),
    this.name = const Value.absent(),
    this.kind = const Value.absent(),
    this.isDefault = const Value.absent(),
    this.isActive = const Value.absent(),
    this.updatedAtMs = const Value.absent(),
    this.rowid = const Value.absent(),
  });
  LocationsCompanion.insert({
    required String id,
    required String name,
    this.kind = const Value.absent(),
    this.isDefault = const Value.absent(),
    this.isActive = const Value.absent(),
    required int updatedAtMs,
    this.rowid = const Value.absent(),
  }) : id = Value(id),
       name = Value(name),
       updatedAtMs = Value(updatedAtMs);
  static Insertable<Location> custom({
    Expression<String>? id,
    Expression<String>? name,
    Expression<String>? kind,
    Expression<bool>? isDefault,
    Expression<bool>? isActive,
    Expression<int>? updatedAtMs,
    Expression<int>? rowid,
  }) {
    return RawValuesInsertable({
      if (id != null) 'id': id,
      if (name != null) 'name': name,
      if (kind != null) 'kind': kind,
      if (isDefault != null) 'is_default': isDefault,
      if (isActive != null) 'is_active': isActive,
      if (updatedAtMs != null) 'updated_at_ms': updatedAtMs,
      if (rowid != null) 'rowid': rowid,
    });
  }

  LocationsCompanion copyWith({
    Value<String>? id,
    Value<String>? name,
    Value<String>? kind,
    Value<bool>? isDefault,
    Value<bool>? isActive,
    Value<int>? updatedAtMs,
    Value<int>? rowid,
  }) {
    return LocationsCompanion(
      id: id ?? this.id,
      name: name ?? this.name,
      kind: kind ?? this.kind,
      isDefault: isDefault ?? this.isDefault,
      isActive: isActive ?? this.isActive,
      updatedAtMs: updatedAtMs ?? this.updatedAtMs,
      rowid: rowid ?? this.rowid,
    );
  }

  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    if (id.present) {
      map['id'] = Variable<String>(id.value);
    }
    if (name.present) {
      map['name'] = Variable<String>(name.value);
    }
    if (kind.present) {
      map['kind'] = Variable<String>(kind.value);
    }
    if (isDefault.present) {
      map['is_default'] = Variable<bool>(isDefault.value);
    }
    if (isActive.present) {
      map['is_active'] = Variable<bool>(isActive.value);
    }
    if (updatedAtMs.present) {
      map['updated_at_ms'] = Variable<int>(updatedAtMs.value);
    }
    if (rowid.present) {
      map['rowid'] = Variable<int>(rowid.value);
    }
    return map;
  }

  @override
  String toString() {
    return (StringBuffer('LocationsCompanion(')
          ..write('id: $id, ')
          ..write('name: $name, ')
          ..write('kind: $kind, ')
          ..write('isDefault: $isDefault, ')
          ..write('isActive: $isActive, ')
          ..write('updatedAtMs: $updatedAtMs, ')
          ..write('rowid: $rowid')
          ..write(')'))
        .toString();
  }
}

class $SalesTable extends Sales with TableInfo<$SalesTable, Sale> {
  @override
  final GeneratedDatabase attachedDatabase;
  final String? _alias;
  $SalesTable(this.attachedDatabase, [this._alias]);
  static const VerificationMeta _idMeta = const VerificationMeta('id');
  @override
  late final GeneratedColumn<String> id = GeneratedColumn<String>(
    'id',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _locationIdMeta = const VerificationMeta(
    'locationId',
  );
  @override
  late final GeneratedColumn<String> locationId = GeneratedColumn<String>(
    'location_id',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _customerIdMeta = const VerificationMeta(
    'customerId',
  );
  @override
  late final GeneratedColumn<String> customerId = GeneratedColumn<String>(
    'customer_id',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _totalCentsMeta = const VerificationMeta(
    'totalCents',
  );
  @override
  late final GeneratedColumn<int> totalCents = GeneratedColumn<int>(
    'total_cents',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _dueDateMeta = const VerificationMeta(
    'dueDate',
  );
  @override
  late final GeneratedColumn<String> dueDate = GeneratedColumn<String>(
    'due_date',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _noteMeta = const VerificationMeta('note');
  @override
  late final GeneratedColumn<String> note = GeneratedColumn<String>(
    'note',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _legacyMeta = const VerificationMeta('legacy');
  @override
  late final GeneratedColumn<bool> legacy = GeneratedColumn<bool>(
    'legacy',
    aliasedName,
    false,
    type: DriftSqlType.bool,
    requiredDuringInsert: false,
    defaultConstraints: GeneratedColumn.constraintIsAlways(
      'CHECK ("legacy" IN (0, 1))',
    ),
    defaultValue: const Constant(false),
  );
  static const VerificationMeta _voidedAtMsMeta = const VerificationMeta(
    'voidedAtMs',
  );
  @override
  late final GeneratedColumn<int> voidedAtMs = GeneratedColumn<int>(
    'voided_at_ms',
    aliasedName,
    true,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _createdAtMsMeta = const VerificationMeta(
    'createdAtMs',
  );
  @override
  late final GeneratedColumn<int> createdAtMs = GeneratedColumn<int>(
    'created_at_ms',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _updatedAtMsMeta = const VerificationMeta(
    'updatedAtMs',
  );
  @override
  late final GeneratedColumn<int> updatedAtMs = GeneratedColumn<int>(
    'updated_at_ms',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: true,
  );
  @override
  List<GeneratedColumn> get $columns => [
    id,
    locationId,
    customerId,
    totalCents,
    dueDate,
    note,
    legacy,
    voidedAtMs,
    createdAtMs,
    updatedAtMs,
  ];
  @override
  String get aliasedName => _alias ?? actualTableName;
  @override
  String get actualTableName => $name;
  static const String $name = 'sales';
  @override
  VerificationContext validateIntegrity(
    Insertable<Sale> instance, {
    bool isInserting = false,
  }) {
    final context = VerificationContext();
    final data = instance.toColumns(true);
    if (data.containsKey('id')) {
      context.handle(_idMeta, id.isAcceptableOrUnknown(data['id']!, _idMeta));
    } else if (isInserting) {
      context.missing(_idMeta);
    }
    if (data.containsKey('location_id')) {
      context.handle(
        _locationIdMeta,
        locationId.isAcceptableOrUnknown(data['location_id']!, _locationIdMeta),
      );
    }
    if (data.containsKey('customer_id')) {
      context.handle(
        _customerIdMeta,
        customerId.isAcceptableOrUnknown(data['customer_id']!, _customerIdMeta),
      );
    }
    if (data.containsKey('total_cents')) {
      context.handle(
        _totalCentsMeta,
        totalCents.isAcceptableOrUnknown(data['total_cents']!, _totalCentsMeta),
      );
    } else if (isInserting) {
      context.missing(_totalCentsMeta);
    }
    if (data.containsKey('due_date')) {
      context.handle(
        _dueDateMeta,
        dueDate.isAcceptableOrUnknown(data['due_date']!, _dueDateMeta),
      );
    }
    if (data.containsKey('note')) {
      context.handle(
        _noteMeta,
        note.isAcceptableOrUnknown(data['note']!, _noteMeta),
      );
    }
    if (data.containsKey('legacy')) {
      context.handle(
        _legacyMeta,
        legacy.isAcceptableOrUnknown(data['legacy']!, _legacyMeta),
      );
    }
    if (data.containsKey('voided_at_ms')) {
      context.handle(
        _voidedAtMsMeta,
        voidedAtMs.isAcceptableOrUnknown(
          data['voided_at_ms']!,
          _voidedAtMsMeta,
        ),
      );
    }
    if (data.containsKey('created_at_ms')) {
      context.handle(
        _createdAtMsMeta,
        createdAtMs.isAcceptableOrUnknown(
          data['created_at_ms']!,
          _createdAtMsMeta,
        ),
      );
    } else if (isInserting) {
      context.missing(_createdAtMsMeta);
    }
    if (data.containsKey('updated_at_ms')) {
      context.handle(
        _updatedAtMsMeta,
        updatedAtMs.isAcceptableOrUnknown(
          data['updated_at_ms']!,
          _updatedAtMsMeta,
        ),
      );
    } else if (isInserting) {
      context.missing(_updatedAtMsMeta);
    }
    return context;
  }

  @override
  Set<GeneratedColumn> get $primaryKey => {id};
  @override
  Sale map(Map<String, dynamic> data, {String? tablePrefix}) {
    final effectivePrefix = tablePrefix != null ? '$tablePrefix.' : '';
    return Sale(
      id: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}id'],
      )!,
      locationId: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}location_id'],
      ),
      customerId: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}customer_id'],
      ),
      totalCents: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}total_cents'],
      )!,
      dueDate: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}due_date'],
      ),
      note: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}note'],
      ),
      legacy: attachedDatabase.typeMapping.read(
        DriftSqlType.bool,
        data['${effectivePrefix}legacy'],
      )!,
      voidedAtMs: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}voided_at_ms'],
      ),
      createdAtMs: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}created_at_ms'],
      )!,
      updatedAtMs: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}updated_at_ms'],
      )!,
    );
  }

  @override
  $SalesTable createAlias(String alias) {
    return $SalesTable(attachedDatabase, alias);
  }
}

class Sale extends DataClass implements Insertable<Sale> {
  final String id;
  final String? locationId;
  final String? customerId;
  final int totalCents;
  final String? dueDate;
  final String? note;
  final bool legacy;
  final int? voidedAtMs;
  final int createdAtMs;
  final int updatedAtMs;
  const Sale({
    required this.id,
    this.locationId,
    this.customerId,
    required this.totalCents,
    this.dueDate,
    this.note,
    required this.legacy,
    this.voidedAtMs,
    required this.createdAtMs,
    required this.updatedAtMs,
  });
  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    map['id'] = Variable<String>(id);
    if (!nullToAbsent || locationId != null) {
      map['location_id'] = Variable<String>(locationId);
    }
    if (!nullToAbsent || customerId != null) {
      map['customer_id'] = Variable<String>(customerId);
    }
    map['total_cents'] = Variable<int>(totalCents);
    if (!nullToAbsent || dueDate != null) {
      map['due_date'] = Variable<String>(dueDate);
    }
    if (!nullToAbsent || note != null) {
      map['note'] = Variable<String>(note);
    }
    map['legacy'] = Variable<bool>(legacy);
    if (!nullToAbsent || voidedAtMs != null) {
      map['voided_at_ms'] = Variable<int>(voidedAtMs);
    }
    map['created_at_ms'] = Variable<int>(createdAtMs);
    map['updated_at_ms'] = Variable<int>(updatedAtMs);
    return map;
  }

  SalesCompanion toCompanion(bool nullToAbsent) {
    return SalesCompanion(
      id: Value(id),
      locationId: locationId == null && nullToAbsent
          ? const Value.absent()
          : Value(locationId),
      customerId: customerId == null && nullToAbsent
          ? const Value.absent()
          : Value(customerId),
      totalCents: Value(totalCents),
      dueDate: dueDate == null && nullToAbsent
          ? const Value.absent()
          : Value(dueDate),
      note: note == null && nullToAbsent ? const Value.absent() : Value(note),
      legacy: Value(legacy),
      voidedAtMs: voidedAtMs == null && nullToAbsent
          ? const Value.absent()
          : Value(voidedAtMs),
      createdAtMs: Value(createdAtMs),
      updatedAtMs: Value(updatedAtMs),
    );
  }

  factory Sale.fromJson(
    Map<String, dynamic> json, {
    ValueSerializer? serializer,
  }) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return Sale(
      id: serializer.fromJson<String>(json['id']),
      locationId: serializer.fromJson<String?>(json['locationId']),
      customerId: serializer.fromJson<String?>(json['customerId']),
      totalCents: serializer.fromJson<int>(json['totalCents']),
      dueDate: serializer.fromJson<String?>(json['dueDate']),
      note: serializer.fromJson<String?>(json['note']),
      legacy: serializer.fromJson<bool>(json['legacy']),
      voidedAtMs: serializer.fromJson<int?>(json['voidedAtMs']),
      createdAtMs: serializer.fromJson<int>(json['createdAtMs']),
      updatedAtMs: serializer.fromJson<int>(json['updatedAtMs']),
    );
  }
  @override
  Map<String, dynamic> toJson({ValueSerializer? serializer}) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return <String, dynamic>{
      'id': serializer.toJson<String>(id),
      'locationId': serializer.toJson<String?>(locationId),
      'customerId': serializer.toJson<String?>(customerId),
      'totalCents': serializer.toJson<int>(totalCents),
      'dueDate': serializer.toJson<String?>(dueDate),
      'note': serializer.toJson<String?>(note),
      'legacy': serializer.toJson<bool>(legacy),
      'voidedAtMs': serializer.toJson<int?>(voidedAtMs),
      'createdAtMs': serializer.toJson<int>(createdAtMs),
      'updatedAtMs': serializer.toJson<int>(updatedAtMs),
    };
  }

  Sale copyWith({
    String? id,
    Value<String?> locationId = const Value.absent(),
    Value<String?> customerId = const Value.absent(),
    int? totalCents,
    Value<String?> dueDate = const Value.absent(),
    Value<String?> note = const Value.absent(),
    bool? legacy,
    Value<int?> voidedAtMs = const Value.absent(),
    int? createdAtMs,
    int? updatedAtMs,
  }) => Sale(
    id: id ?? this.id,
    locationId: locationId.present ? locationId.value : this.locationId,
    customerId: customerId.present ? customerId.value : this.customerId,
    totalCents: totalCents ?? this.totalCents,
    dueDate: dueDate.present ? dueDate.value : this.dueDate,
    note: note.present ? note.value : this.note,
    legacy: legacy ?? this.legacy,
    voidedAtMs: voidedAtMs.present ? voidedAtMs.value : this.voidedAtMs,
    createdAtMs: createdAtMs ?? this.createdAtMs,
    updatedAtMs: updatedAtMs ?? this.updatedAtMs,
  );
  Sale copyWithCompanion(SalesCompanion data) {
    return Sale(
      id: data.id.present ? data.id.value : this.id,
      locationId: data.locationId.present
          ? data.locationId.value
          : this.locationId,
      customerId: data.customerId.present
          ? data.customerId.value
          : this.customerId,
      totalCents: data.totalCents.present
          ? data.totalCents.value
          : this.totalCents,
      dueDate: data.dueDate.present ? data.dueDate.value : this.dueDate,
      note: data.note.present ? data.note.value : this.note,
      legacy: data.legacy.present ? data.legacy.value : this.legacy,
      voidedAtMs: data.voidedAtMs.present
          ? data.voidedAtMs.value
          : this.voidedAtMs,
      createdAtMs: data.createdAtMs.present
          ? data.createdAtMs.value
          : this.createdAtMs,
      updatedAtMs: data.updatedAtMs.present
          ? data.updatedAtMs.value
          : this.updatedAtMs,
    );
  }

  @override
  String toString() {
    return (StringBuffer('Sale(')
          ..write('id: $id, ')
          ..write('locationId: $locationId, ')
          ..write('customerId: $customerId, ')
          ..write('totalCents: $totalCents, ')
          ..write('dueDate: $dueDate, ')
          ..write('note: $note, ')
          ..write('legacy: $legacy, ')
          ..write('voidedAtMs: $voidedAtMs, ')
          ..write('createdAtMs: $createdAtMs, ')
          ..write('updatedAtMs: $updatedAtMs')
          ..write(')'))
        .toString();
  }

  @override
  int get hashCode => Object.hash(
    id,
    locationId,
    customerId,
    totalCents,
    dueDate,
    note,
    legacy,
    voidedAtMs,
    createdAtMs,
    updatedAtMs,
  );
  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      (other is Sale &&
          other.id == this.id &&
          other.locationId == this.locationId &&
          other.customerId == this.customerId &&
          other.totalCents == this.totalCents &&
          other.dueDate == this.dueDate &&
          other.note == this.note &&
          other.legacy == this.legacy &&
          other.voidedAtMs == this.voidedAtMs &&
          other.createdAtMs == this.createdAtMs &&
          other.updatedAtMs == this.updatedAtMs);
}

class SalesCompanion extends UpdateCompanion<Sale> {
  final Value<String> id;
  final Value<String?> locationId;
  final Value<String?> customerId;
  final Value<int> totalCents;
  final Value<String?> dueDate;
  final Value<String?> note;
  final Value<bool> legacy;
  final Value<int?> voidedAtMs;
  final Value<int> createdAtMs;
  final Value<int> updatedAtMs;
  final Value<int> rowid;
  const SalesCompanion({
    this.id = const Value.absent(),
    this.locationId = const Value.absent(),
    this.customerId = const Value.absent(),
    this.totalCents = const Value.absent(),
    this.dueDate = const Value.absent(),
    this.note = const Value.absent(),
    this.legacy = const Value.absent(),
    this.voidedAtMs = const Value.absent(),
    this.createdAtMs = const Value.absent(),
    this.updatedAtMs = const Value.absent(),
    this.rowid = const Value.absent(),
  });
  SalesCompanion.insert({
    required String id,
    this.locationId = const Value.absent(),
    this.customerId = const Value.absent(),
    required int totalCents,
    this.dueDate = const Value.absent(),
    this.note = const Value.absent(),
    this.legacy = const Value.absent(),
    this.voidedAtMs = const Value.absent(),
    required int createdAtMs,
    required int updatedAtMs,
    this.rowid = const Value.absent(),
  }) : id = Value(id),
       totalCents = Value(totalCents),
       createdAtMs = Value(createdAtMs),
       updatedAtMs = Value(updatedAtMs);
  static Insertable<Sale> custom({
    Expression<String>? id,
    Expression<String>? locationId,
    Expression<String>? customerId,
    Expression<int>? totalCents,
    Expression<String>? dueDate,
    Expression<String>? note,
    Expression<bool>? legacy,
    Expression<int>? voidedAtMs,
    Expression<int>? createdAtMs,
    Expression<int>? updatedAtMs,
    Expression<int>? rowid,
  }) {
    return RawValuesInsertable({
      if (id != null) 'id': id,
      if (locationId != null) 'location_id': locationId,
      if (customerId != null) 'customer_id': customerId,
      if (totalCents != null) 'total_cents': totalCents,
      if (dueDate != null) 'due_date': dueDate,
      if (note != null) 'note': note,
      if (legacy != null) 'legacy': legacy,
      if (voidedAtMs != null) 'voided_at_ms': voidedAtMs,
      if (createdAtMs != null) 'created_at_ms': createdAtMs,
      if (updatedAtMs != null) 'updated_at_ms': updatedAtMs,
      if (rowid != null) 'rowid': rowid,
    });
  }

  SalesCompanion copyWith({
    Value<String>? id,
    Value<String?>? locationId,
    Value<String?>? customerId,
    Value<int>? totalCents,
    Value<String?>? dueDate,
    Value<String?>? note,
    Value<bool>? legacy,
    Value<int?>? voidedAtMs,
    Value<int>? createdAtMs,
    Value<int>? updatedAtMs,
    Value<int>? rowid,
  }) {
    return SalesCompanion(
      id: id ?? this.id,
      locationId: locationId ?? this.locationId,
      customerId: customerId ?? this.customerId,
      totalCents: totalCents ?? this.totalCents,
      dueDate: dueDate ?? this.dueDate,
      note: note ?? this.note,
      legacy: legacy ?? this.legacy,
      voidedAtMs: voidedAtMs ?? this.voidedAtMs,
      createdAtMs: createdAtMs ?? this.createdAtMs,
      updatedAtMs: updatedAtMs ?? this.updatedAtMs,
      rowid: rowid ?? this.rowid,
    );
  }

  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    if (id.present) {
      map['id'] = Variable<String>(id.value);
    }
    if (locationId.present) {
      map['location_id'] = Variable<String>(locationId.value);
    }
    if (customerId.present) {
      map['customer_id'] = Variable<String>(customerId.value);
    }
    if (totalCents.present) {
      map['total_cents'] = Variable<int>(totalCents.value);
    }
    if (dueDate.present) {
      map['due_date'] = Variable<String>(dueDate.value);
    }
    if (note.present) {
      map['note'] = Variable<String>(note.value);
    }
    if (legacy.present) {
      map['legacy'] = Variable<bool>(legacy.value);
    }
    if (voidedAtMs.present) {
      map['voided_at_ms'] = Variable<int>(voidedAtMs.value);
    }
    if (createdAtMs.present) {
      map['created_at_ms'] = Variable<int>(createdAtMs.value);
    }
    if (updatedAtMs.present) {
      map['updated_at_ms'] = Variable<int>(updatedAtMs.value);
    }
    if (rowid.present) {
      map['rowid'] = Variable<int>(rowid.value);
    }
    return map;
  }

  @override
  String toString() {
    return (StringBuffer('SalesCompanion(')
          ..write('id: $id, ')
          ..write('locationId: $locationId, ')
          ..write('customerId: $customerId, ')
          ..write('totalCents: $totalCents, ')
          ..write('dueDate: $dueDate, ')
          ..write('note: $note, ')
          ..write('legacy: $legacy, ')
          ..write('voidedAtMs: $voidedAtMs, ')
          ..write('createdAtMs: $createdAtMs, ')
          ..write('updatedAtMs: $updatedAtMs, ')
          ..write('rowid: $rowid')
          ..write(')'))
        .toString();
  }
}

class $PaymentsTable extends Payments with TableInfo<$PaymentsTable, Payment> {
  @override
  final GeneratedDatabase attachedDatabase;
  final String? _alias;
  $PaymentsTable(this.attachedDatabase, [this._alias]);
  static const VerificationMeta _idMeta = const VerificationMeta('id');
  @override
  late final GeneratedColumn<String> id = GeneratedColumn<String>(
    'id',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _saleIdMeta = const VerificationMeta('saleId');
  @override
  late final GeneratedColumn<String> saleId = GeneratedColumn<String>(
    'sale_id',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _customerIdMeta = const VerificationMeta(
    'customerId',
  );
  @override
  late final GeneratedColumn<String> customerId = GeneratedColumn<String>(
    'customer_id',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _locationIdMeta = const VerificationMeta(
    'locationId',
  );
  @override
  late final GeneratedColumn<String> locationId = GeneratedColumn<String>(
    'location_id',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _methodMeta = const VerificationMeta('method');
  @override
  late final GeneratedColumn<String> method = GeneratedColumn<String>(
    'method',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _providerKeyMeta = const VerificationMeta(
    'providerKey',
  );
  @override
  late final GeneratedColumn<String> providerKey = GeneratedColumn<String>(
    'provider_key',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _amountCentsMeta = const VerificationMeta(
    'amountCents',
  );
  @override
  late final GeneratedColumn<int> amountCents = GeneratedColumn<int>(
    'amount_cents',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _referenceMeta = const VerificationMeta(
    'reference',
  );
  @override
  late final GeneratedColumn<String> reference = GeneratedColumn<String>(
    'reference',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _receivedAtMsMeta = const VerificationMeta(
    'receivedAtMs',
  );
  @override
  late final GeneratedColumn<int> receivedAtMs = GeneratedColumn<int>(
    'received_at_ms',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _createdAtMsMeta = const VerificationMeta(
    'createdAtMs',
  );
  @override
  late final GeneratedColumn<int> createdAtMs = GeneratedColumn<int>(
    'created_at_ms',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: true,
  );
  @override
  List<GeneratedColumn> get $columns => [
    id,
    saleId,
    customerId,
    locationId,
    method,
    providerKey,
    amountCents,
    reference,
    receivedAtMs,
    createdAtMs,
  ];
  @override
  String get aliasedName => _alias ?? actualTableName;
  @override
  String get actualTableName => $name;
  static const String $name = 'payments';
  @override
  VerificationContext validateIntegrity(
    Insertable<Payment> instance, {
    bool isInserting = false,
  }) {
    final context = VerificationContext();
    final data = instance.toColumns(true);
    if (data.containsKey('id')) {
      context.handle(_idMeta, id.isAcceptableOrUnknown(data['id']!, _idMeta));
    } else if (isInserting) {
      context.missing(_idMeta);
    }
    if (data.containsKey('sale_id')) {
      context.handle(
        _saleIdMeta,
        saleId.isAcceptableOrUnknown(data['sale_id']!, _saleIdMeta),
      );
    }
    if (data.containsKey('customer_id')) {
      context.handle(
        _customerIdMeta,
        customerId.isAcceptableOrUnknown(data['customer_id']!, _customerIdMeta),
      );
    }
    if (data.containsKey('location_id')) {
      context.handle(
        _locationIdMeta,
        locationId.isAcceptableOrUnknown(data['location_id']!, _locationIdMeta),
      );
    }
    if (data.containsKey('method')) {
      context.handle(
        _methodMeta,
        method.isAcceptableOrUnknown(data['method']!, _methodMeta),
      );
    } else if (isInserting) {
      context.missing(_methodMeta);
    }
    if (data.containsKey('provider_key')) {
      context.handle(
        _providerKeyMeta,
        providerKey.isAcceptableOrUnknown(
          data['provider_key']!,
          _providerKeyMeta,
        ),
      );
    }
    if (data.containsKey('amount_cents')) {
      context.handle(
        _amountCentsMeta,
        amountCents.isAcceptableOrUnknown(
          data['amount_cents']!,
          _amountCentsMeta,
        ),
      );
    } else if (isInserting) {
      context.missing(_amountCentsMeta);
    }
    if (data.containsKey('reference')) {
      context.handle(
        _referenceMeta,
        reference.isAcceptableOrUnknown(data['reference']!, _referenceMeta),
      );
    }
    if (data.containsKey('received_at_ms')) {
      context.handle(
        _receivedAtMsMeta,
        receivedAtMs.isAcceptableOrUnknown(
          data['received_at_ms']!,
          _receivedAtMsMeta,
        ),
      );
    } else if (isInserting) {
      context.missing(_receivedAtMsMeta);
    }
    if (data.containsKey('created_at_ms')) {
      context.handle(
        _createdAtMsMeta,
        createdAtMs.isAcceptableOrUnknown(
          data['created_at_ms']!,
          _createdAtMsMeta,
        ),
      );
    } else if (isInserting) {
      context.missing(_createdAtMsMeta);
    }
    return context;
  }

  @override
  Set<GeneratedColumn> get $primaryKey => {id};
  @override
  Payment map(Map<String, dynamic> data, {String? tablePrefix}) {
    final effectivePrefix = tablePrefix != null ? '$tablePrefix.' : '';
    return Payment(
      id: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}id'],
      )!,
      saleId: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}sale_id'],
      ),
      customerId: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}customer_id'],
      ),
      locationId: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}location_id'],
      ),
      method: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}method'],
      )!,
      providerKey: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}provider_key'],
      ),
      amountCents: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}amount_cents'],
      )!,
      reference: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}reference'],
      ),
      receivedAtMs: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}received_at_ms'],
      )!,
      createdAtMs: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}created_at_ms'],
      )!,
    );
  }

  @override
  $PaymentsTable createAlias(String alias) {
    return $PaymentsTable(attachedDatabase, alias);
  }
}

class Payment extends DataClass implements Insertable<Payment> {
  final String id;
  final String? saleId;
  final String? customerId;
  final String? locationId;
  final String method;
  final String? providerKey;
  final int amountCents;
  final String? reference;
  final int receivedAtMs;
  final int createdAtMs;
  const Payment({
    required this.id,
    this.saleId,
    this.customerId,
    this.locationId,
    required this.method,
    this.providerKey,
    required this.amountCents,
    this.reference,
    required this.receivedAtMs,
    required this.createdAtMs,
  });
  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    map['id'] = Variable<String>(id);
    if (!nullToAbsent || saleId != null) {
      map['sale_id'] = Variable<String>(saleId);
    }
    if (!nullToAbsent || customerId != null) {
      map['customer_id'] = Variable<String>(customerId);
    }
    if (!nullToAbsent || locationId != null) {
      map['location_id'] = Variable<String>(locationId);
    }
    map['method'] = Variable<String>(method);
    if (!nullToAbsent || providerKey != null) {
      map['provider_key'] = Variable<String>(providerKey);
    }
    map['amount_cents'] = Variable<int>(amountCents);
    if (!nullToAbsent || reference != null) {
      map['reference'] = Variable<String>(reference);
    }
    map['received_at_ms'] = Variable<int>(receivedAtMs);
    map['created_at_ms'] = Variable<int>(createdAtMs);
    return map;
  }

  PaymentsCompanion toCompanion(bool nullToAbsent) {
    return PaymentsCompanion(
      id: Value(id),
      saleId: saleId == null && nullToAbsent
          ? const Value.absent()
          : Value(saleId),
      customerId: customerId == null && nullToAbsent
          ? const Value.absent()
          : Value(customerId),
      locationId: locationId == null && nullToAbsent
          ? const Value.absent()
          : Value(locationId),
      method: Value(method),
      providerKey: providerKey == null && nullToAbsent
          ? const Value.absent()
          : Value(providerKey),
      amountCents: Value(amountCents),
      reference: reference == null && nullToAbsent
          ? const Value.absent()
          : Value(reference),
      receivedAtMs: Value(receivedAtMs),
      createdAtMs: Value(createdAtMs),
    );
  }

  factory Payment.fromJson(
    Map<String, dynamic> json, {
    ValueSerializer? serializer,
  }) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return Payment(
      id: serializer.fromJson<String>(json['id']),
      saleId: serializer.fromJson<String?>(json['saleId']),
      customerId: serializer.fromJson<String?>(json['customerId']),
      locationId: serializer.fromJson<String?>(json['locationId']),
      method: serializer.fromJson<String>(json['method']),
      providerKey: serializer.fromJson<String?>(json['providerKey']),
      amountCents: serializer.fromJson<int>(json['amountCents']),
      reference: serializer.fromJson<String?>(json['reference']),
      receivedAtMs: serializer.fromJson<int>(json['receivedAtMs']),
      createdAtMs: serializer.fromJson<int>(json['createdAtMs']),
    );
  }
  @override
  Map<String, dynamic> toJson({ValueSerializer? serializer}) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return <String, dynamic>{
      'id': serializer.toJson<String>(id),
      'saleId': serializer.toJson<String?>(saleId),
      'customerId': serializer.toJson<String?>(customerId),
      'locationId': serializer.toJson<String?>(locationId),
      'method': serializer.toJson<String>(method),
      'providerKey': serializer.toJson<String?>(providerKey),
      'amountCents': serializer.toJson<int>(amountCents),
      'reference': serializer.toJson<String?>(reference),
      'receivedAtMs': serializer.toJson<int>(receivedAtMs),
      'createdAtMs': serializer.toJson<int>(createdAtMs),
    };
  }

  Payment copyWith({
    String? id,
    Value<String?> saleId = const Value.absent(),
    Value<String?> customerId = const Value.absent(),
    Value<String?> locationId = const Value.absent(),
    String? method,
    Value<String?> providerKey = const Value.absent(),
    int? amountCents,
    Value<String?> reference = const Value.absent(),
    int? receivedAtMs,
    int? createdAtMs,
  }) => Payment(
    id: id ?? this.id,
    saleId: saleId.present ? saleId.value : this.saleId,
    customerId: customerId.present ? customerId.value : this.customerId,
    locationId: locationId.present ? locationId.value : this.locationId,
    method: method ?? this.method,
    providerKey: providerKey.present ? providerKey.value : this.providerKey,
    amountCents: amountCents ?? this.amountCents,
    reference: reference.present ? reference.value : this.reference,
    receivedAtMs: receivedAtMs ?? this.receivedAtMs,
    createdAtMs: createdAtMs ?? this.createdAtMs,
  );
  Payment copyWithCompanion(PaymentsCompanion data) {
    return Payment(
      id: data.id.present ? data.id.value : this.id,
      saleId: data.saleId.present ? data.saleId.value : this.saleId,
      customerId: data.customerId.present
          ? data.customerId.value
          : this.customerId,
      locationId: data.locationId.present
          ? data.locationId.value
          : this.locationId,
      method: data.method.present ? data.method.value : this.method,
      providerKey: data.providerKey.present
          ? data.providerKey.value
          : this.providerKey,
      amountCents: data.amountCents.present
          ? data.amountCents.value
          : this.amountCents,
      reference: data.reference.present ? data.reference.value : this.reference,
      receivedAtMs: data.receivedAtMs.present
          ? data.receivedAtMs.value
          : this.receivedAtMs,
      createdAtMs: data.createdAtMs.present
          ? data.createdAtMs.value
          : this.createdAtMs,
    );
  }

  @override
  String toString() {
    return (StringBuffer('Payment(')
          ..write('id: $id, ')
          ..write('saleId: $saleId, ')
          ..write('customerId: $customerId, ')
          ..write('locationId: $locationId, ')
          ..write('method: $method, ')
          ..write('providerKey: $providerKey, ')
          ..write('amountCents: $amountCents, ')
          ..write('reference: $reference, ')
          ..write('receivedAtMs: $receivedAtMs, ')
          ..write('createdAtMs: $createdAtMs')
          ..write(')'))
        .toString();
  }

  @override
  int get hashCode => Object.hash(
    id,
    saleId,
    customerId,
    locationId,
    method,
    providerKey,
    amountCents,
    reference,
    receivedAtMs,
    createdAtMs,
  );
  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      (other is Payment &&
          other.id == this.id &&
          other.saleId == this.saleId &&
          other.customerId == this.customerId &&
          other.locationId == this.locationId &&
          other.method == this.method &&
          other.providerKey == this.providerKey &&
          other.amountCents == this.amountCents &&
          other.reference == this.reference &&
          other.receivedAtMs == this.receivedAtMs &&
          other.createdAtMs == this.createdAtMs);
}

class PaymentsCompanion extends UpdateCompanion<Payment> {
  final Value<String> id;
  final Value<String?> saleId;
  final Value<String?> customerId;
  final Value<String?> locationId;
  final Value<String> method;
  final Value<String?> providerKey;
  final Value<int> amountCents;
  final Value<String?> reference;
  final Value<int> receivedAtMs;
  final Value<int> createdAtMs;
  final Value<int> rowid;
  const PaymentsCompanion({
    this.id = const Value.absent(),
    this.saleId = const Value.absent(),
    this.customerId = const Value.absent(),
    this.locationId = const Value.absent(),
    this.method = const Value.absent(),
    this.providerKey = const Value.absent(),
    this.amountCents = const Value.absent(),
    this.reference = const Value.absent(),
    this.receivedAtMs = const Value.absent(),
    this.createdAtMs = const Value.absent(),
    this.rowid = const Value.absent(),
  });
  PaymentsCompanion.insert({
    required String id,
    this.saleId = const Value.absent(),
    this.customerId = const Value.absent(),
    this.locationId = const Value.absent(),
    required String method,
    this.providerKey = const Value.absent(),
    required int amountCents,
    this.reference = const Value.absent(),
    required int receivedAtMs,
    required int createdAtMs,
    this.rowid = const Value.absent(),
  }) : id = Value(id),
       method = Value(method),
       amountCents = Value(amountCents),
       receivedAtMs = Value(receivedAtMs),
       createdAtMs = Value(createdAtMs);
  static Insertable<Payment> custom({
    Expression<String>? id,
    Expression<String>? saleId,
    Expression<String>? customerId,
    Expression<String>? locationId,
    Expression<String>? method,
    Expression<String>? providerKey,
    Expression<int>? amountCents,
    Expression<String>? reference,
    Expression<int>? receivedAtMs,
    Expression<int>? createdAtMs,
    Expression<int>? rowid,
  }) {
    return RawValuesInsertable({
      if (id != null) 'id': id,
      if (saleId != null) 'sale_id': saleId,
      if (customerId != null) 'customer_id': customerId,
      if (locationId != null) 'location_id': locationId,
      if (method != null) 'method': method,
      if (providerKey != null) 'provider_key': providerKey,
      if (amountCents != null) 'amount_cents': amountCents,
      if (reference != null) 'reference': reference,
      if (receivedAtMs != null) 'received_at_ms': receivedAtMs,
      if (createdAtMs != null) 'created_at_ms': createdAtMs,
      if (rowid != null) 'rowid': rowid,
    });
  }

  PaymentsCompanion copyWith({
    Value<String>? id,
    Value<String?>? saleId,
    Value<String?>? customerId,
    Value<String?>? locationId,
    Value<String>? method,
    Value<String?>? providerKey,
    Value<int>? amountCents,
    Value<String?>? reference,
    Value<int>? receivedAtMs,
    Value<int>? createdAtMs,
    Value<int>? rowid,
  }) {
    return PaymentsCompanion(
      id: id ?? this.id,
      saleId: saleId ?? this.saleId,
      customerId: customerId ?? this.customerId,
      locationId: locationId ?? this.locationId,
      method: method ?? this.method,
      providerKey: providerKey ?? this.providerKey,
      amountCents: amountCents ?? this.amountCents,
      reference: reference ?? this.reference,
      receivedAtMs: receivedAtMs ?? this.receivedAtMs,
      createdAtMs: createdAtMs ?? this.createdAtMs,
      rowid: rowid ?? this.rowid,
    );
  }

  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    if (id.present) {
      map['id'] = Variable<String>(id.value);
    }
    if (saleId.present) {
      map['sale_id'] = Variable<String>(saleId.value);
    }
    if (customerId.present) {
      map['customer_id'] = Variable<String>(customerId.value);
    }
    if (locationId.present) {
      map['location_id'] = Variable<String>(locationId.value);
    }
    if (method.present) {
      map['method'] = Variable<String>(method.value);
    }
    if (providerKey.present) {
      map['provider_key'] = Variable<String>(providerKey.value);
    }
    if (amountCents.present) {
      map['amount_cents'] = Variable<int>(amountCents.value);
    }
    if (reference.present) {
      map['reference'] = Variable<String>(reference.value);
    }
    if (receivedAtMs.present) {
      map['received_at_ms'] = Variable<int>(receivedAtMs.value);
    }
    if (createdAtMs.present) {
      map['created_at_ms'] = Variable<int>(createdAtMs.value);
    }
    if (rowid.present) {
      map['rowid'] = Variable<int>(rowid.value);
    }
    return map;
  }

  @override
  String toString() {
    return (StringBuffer('PaymentsCompanion(')
          ..write('id: $id, ')
          ..write('saleId: $saleId, ')
          ..write('customerId: $customerId, ')
          ..write('locationId: $locationId, ')
          ..write('method: $method, ')
          ..write('providerKey: $providerKey, ')
          ..write('amountCents: $amountCents, ')
          ..write('reference: $reference, ')
          ..write('receivedAtMs: $receivedAtMs, ')
          ..write('createdAtMs: $createdAtMs, ')
          ..write('rowid: $rowid')
          ..write(')'))
        .toString();
  }
}

class $CustomersTable extends Customers
    with TableInfo<$CustomersTable, Customer> {
  @override
  final GeneratedDatabase attachedDatabase;
  final String? _alias;
  $CustomersTable(this.attachedDatabase, [this._alias]);
  static const VerificationMeta _idMeta = const VerificationMeta('id');
  @override
  late final GeneratedColumn<String> id = GeneratedColumn<String>(
    'id',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _nameMeta = const VerificationMeta('name');
  @override
  late final GeneratedColumn<String> name = GeneratedColumn<String>(
    'name',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _phoneMeta = const VerificationMeta('phone');
  @override
  late final GeneratedColumn<String> phone = GeneratedColumn<String>(
    'phone',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _noteMeta = const VerificationMeta('note');
  @override
  late final GeneratedColumn<String> note = GeneratedColumn<String>(
    'note',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _createdAtMsMeta = const VerificationMeta(
    'createdAtMs',
  );
  @override
  late final GeneratedColumn<int> createdAtMs = GeneratedColumn<int>(
    'created_at_ms',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _updatedAtMsMeta = const VerificationMeta(
    'updatedAtMs',
  );
  @override
  late final GeneratedColumn<int> updatedAtMs = GeneratedColumn<int>(
    'updated_at_ms',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _deletedAtMsMeta = const VerificationMeta(
    'deletedAtMs',
  );
  @override
  late final GeneratedColumn<int> deletedAtMs = GeneratedColumn<int>(
    'deleted_at_ms',
    aliasedName,
    true,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
  );
  @override
  List<GeneratedColumn> get $columns => [
    id,
    name,
    phone,
    note,
    createdAtMs,
    updatedAtMs,
    deletedAtMs,
  ];
  @override
  String get aliasedName => _alias ?? actualTableName;
  @override
  String get actualTableName => $name;
  static const String $name = 'customers';
  @override
  VerificationContext validateIntegrity(
    Insertable<Customer> instance, {
    bool isInserting = false,
  }) {
    final context = VerificationContext();
    final data = instance.toColumns(true);
    if (data.containsKey('id')) {
      context.handle(_idMeta, id.isAcceptableOrUnknown(data['id']!, _idMeta));
    } else if (isInserting) {
      context.missing(_idMeta);
    }
    if (data.containsKey('name')) {
      context.handle(
        _nameMeta,
        name.isAcceptableOrUnknown(data['name']!, _nameMeta),
      );
    } else if (isInserting) {
      context.missing(_nameMeta);
    }
    if (data.containsKey('phone')) {
      context.handle(
        _phoneMeta,
        phone.isAcceptableOrUnknown(data['phone']!, _phoneMeta),
      );
    }
    if (data.containsKey('note')) {
      context.handle(
        _noteMeta,
        note.isAcceptableOrUnknown(data['note']!, _noteMeta),
      );
    }
    if (data.containsKey('created_at_ms')) {
      context.handle(
        _createdAtMsMeta,
        createdAtMs.isAcceptableOrUnknown(
          data['created_at_ms']!,
          _createdAtMsMeta,
        ),
      );
    } else if (isInserting) {
      context.missing(_createdAtMsMeta);
    }
    if (data.containsKey('updated_at_ms')) {
      context.handle(
        _updatedAtMsMeta,
        updatedAtMs.isAcceptableOrUnknown(
          data['updated_at_ms']!,
          _updatedAtMsMeta,
        ),
      );
    } else if (isInserting) {
      context.missing(_updatedAtMsMeta);
    }
    if (data.containsKey('deleted_at_ms')) {
      context.handle(
        _deletedAtMsMeta,
        deletedAtMs.isAcceptableOrUnknown(
          data['deleted_at_ms']!,
          _deletedAtMsMeta,
        ),
      );
    }
    return context;
  }

  @override
  Set<GeneratedColumn> get $primaryKey => {id};
  @override
  Customer map(Map<String, dynamic> data, {String? tablePrefix}) {
    final effectivePrefix = tablePrefix != null ? '$tablePrefix.' : '';
    return Customer(
      id: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}id'],
      )!,
      name: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}name'],
      )!,
      phone: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}phone'],
      ),
      note: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}note'],
      ),
      createdAtMs: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}created_at_ms'],
      )!,
      updatedAtMs: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}updated_at_ms'],
      )!,
      deletedAtMs: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}deleted_at_ms'],
      ),
    );
  }

  @override
  $CustomersTable createAlias(String alias) {
    return $CustomersTable(attachedDatabase, alias);
  }
}

class Customer extends DataClass implements Insertable<Customer> {
  final String id;
  final String name;
  final String? phone;
  final String? note;
  final int createdAtMs;
  final int updatedAtMs;
  final int? deletedAtMs;
  const Customer({
    required this.id,
    required this.name,
    this.phone,
    this.note,
    required this.createdAtMs,
    required this.updatedAtMs,
    this.deletedAtMs,
  });
  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    map['id'] = Variable<String>(id);
    map['name'] = Variable<String>(name);
    if (!nullToAbsent || phone != null) {
      map['phone'] = Variable<String>(phone);
    }
    if (!nullToAbsent || note != null) {
      map['note'] = Variable<String>(note);
    }
    map['created_at_ms'] = Variable<int>(createdAtMs);
    map['updated_at_ms'] = Variable<int>(updatedAtMs);
    if (!nullToAbsent || deletedAtMs != null) {
      map['deleted_at_ms'] = Variable<int>(deletedAtMs);
    }
    return map;
  }

  CustomersCompanion toCompanion(bool nullToAbsent) {
    return CustomersCompanion(
      id: Value(id),
      name: Value(name),
      phone: phone == null && nullToAbsent
          ? const Value.absent()
          : Value(phone),
      note: note == null && nullToAbsent ? const Value.absent() : Value(note),
      createdAtMs: Value(createdAtMs),
      updatedAtMs: Value(updatedAtMs),
      deletedAtMs: deletedAtMs == null && nullToAbsent
          ? const Value.absent()
          : Value(deletedAtMs),
    );
  }

  factory Customer.fromJson(
    Map<String, dynamic> json, {
    ValueSerializer? serializer,
  }) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return Customer(
      id: serializer.fromJson<String>(json['id']),
      name: serializer.fromJson<String>(json['name']),
      phone: serializer.fromJson<String?>(json['phone']),
      note: serializer.fromJson<String?>(json['note']),
      createdAtMs: serializer.fromJson<int>(json['createdAtMs']),
      updatedAtMs: serializer.fromJson<int>(json['updatedAtMs']),
      deletedAtMs: serializer.fromJson<int?>(json['deletedAtMs']),
    );
  }
  @override
  Map<String, dynamic> toJson({ValueSerializer? serializer}) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return <String, dynamic>{
      'id': serializer.toJson<String>(id),
      'name': serializer.toJson<String>(name),
      'phone': serializer.toJson<String?>(phone),
      'note': serializer.toJson<String?>(note),
      'createdAtMs': serializer.toJson<int>(createdAtMs),
      'updatedAtMs': serializer.toJson<int>(updatedAtMs),
      'deletedAtMs': serializer.toJson<int?>(deletedAtMs),
    };
  }

  Customer copyWith({
    String? id,
    String? name,
    Value<String?> phone = const Value.absent(),
    Value<String?> note = const Value.absent(),
    int? createdAtMs,
    int? updatedAtMs,
    Value<int?> deletedAtMs = const Value.absent(),
  }) => Customer(
    id: id ?? this.id,
    name: name ?? this.name,
    phone: phone.present ? phone.value : this.phone,
    note: note.present ? note.value : this.note,
    createdAtMs: createdAtMs ?? this.createdAtMs,
    updatedAtMs: updatedAtMs ?? this.updatedAtMs,
    deletedAtMs: deletedAtMs.present ? deletedAtMs.value : this.deletedAtMs,
  );
  Customer copyWithCompanion(CustomersCompanion data) {
    return Customer(
      id: data.id.present ? data.id.value : this.id,
      name: data.name.present ? data.name.value : this.name,
      phone: data.phone.present ? data.phone.value : this.phone,
      note: data.note.present ? data.note.value : this.note,
      createdAtMs: data.createdAtMs.present
          ? data.createdAtMs.value
          : this.createdAtMs,
      updatedAtMs: data.updatedAtMs.present
          ? data.updatedAtMs.value
          : this.updatedAtMs,
      deletedAtMs: data.deletedAtMs.present
          ? data.deletedAtMs.value
          : this.deletedAtMs,
    );
  }

  @override
  String toString() {
    return (StringBuffer('Customer(')
          ..write('id: $id, ')
          ..write('name: $name, ')
          ..write('phone: $phone, ')
          ..write('note: $note, ')
          ..write('createdAtMs: $createdAtMs, ')
          ..write('updatedAtMs: $updatedAtMs, ')
          ..write('deletedAtMs: $deletedAtMs')
          ..write(')'))
        .toString();
  }

  @override
  int get hashCode =>
      Object.hash(id, name, phone, note, createdAtMs, updatedAtMs, deletedAtMs);
  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      (other is Customer &&
          other.id == this.id &&
          other.name == this.name &&
          other.phone == this.phone &&
          other.note == this.note &&
          other.createdAtMs == this.createdAtMs &&
          other.updatedAtMs == this.updatedAtMs &&
          other.deletedAtMs == this.deletedAtMs);
}

class CustomersCompanion extends UpdateCompanion<Customer> {
  final Value<String> id;
  final Value<String> name;
  final Value<String?> phone;
  final Value<String?> note;
  final Value<int> createdAtMs;
  final Value<int> updatedAtMs;
  final Value<int?> deletedAtMs;
  final Value<int> rowid;
  const CustomersCompanion({
    this.id = const Value.absent(),
    this.name = const Value.absent(),
    this.phone = const Value.absent(),
    this.note = const Value.absent(),
    this.createdAtMs = const Value.absent(),
    this.updatedAtMs = const Value.absent(),
    this.deletedAtMs = const Value.absent(),
    this.rowid = const Value.absent(),
  });
  CustomersCompanion.insert({
    required String id,
    required String name,
    this.phone = const Value.absent(),
    this.note = const Value.absent(),
    required int createdAtMs,
    required int updatedAtMs,
    this.deletedAtMs = const Value.absent(),
    this.rowid = const Value.absent(),
  }) : id = Value(id),
       name = Value(name),
       createdAtMs = Value(createdAtMs),
       updatedAtMs = Value(updatedAtMs);
  static Insertable<Customer> custom({
    Expression<String>? id,
    Expression<String>? name,
    Expression<String>? phone,
    Expression<String>? note,
    Expression<int>? createdAtMs,
    Expression<int>? updatedAtMs,
    Expression<int>? deletedAtMs,
    Expression<int>? rowid,
  }) {
    return RawValuesInsertable({
      if (id != null) 'id': id,
      if (name != null) 'name': name,
      if (phone != null) 'phone': phone,
      if (note != null) 'note': note,
      if (createdAtMs != null) 'created_at_ms': createdAtMs,
      if (updatedAtMs != null) 'updated_at_ms': updatedAtMs,
      if (deletedAtMs != null) 'deleted_at_ms': deletedAtMs,
      if (rowid != null) 'rowid': rowid,
    });
  }

  CustomersCompanion copyWith({
    Value<String>? id,
    Value<String>? name,
    Value<String?>? phone,
    Value<String?>? note,
    Value<int>? createdAtMs,
    Value<int>? updatedAtMs,
    Value<int?>? deletedAtMs,
    Value<int>? rowid,
  }) {
    return CustomersCompanion(
      id: id ?? this.id,
      name: name ?? this.name,
      phone: phone ?? this.phone,
      note: note ?? this.note,
      createdAtMs: createdAtMs ?? this.createdAtMs,
      updatedAtMs: updatedAtMs ?? this.updatedAtMs,
      deletedAtMs: deletedAtMs ?? this.deletedAtMs,
      rowid: rowid ?? this.rowid,
    );
  }

  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    if (id.present) {
      map['id'] = Variable<String>(id.value);
    }
    if (name.present) {
      map['name'] = Variable<String>(name.value);
    }
    if (phone.present) {
      map['phone'] = Variable<String>(phone.value);
    }
    if (note.present) {
      map['note'] = Variable<String>(note.value);
    }
    if (createdAtMs.present) {
      map['created_at_ms'] = Variable<int>(createdAtMs.value);
    }
    if (updatedAtMs.present) {
      map['updated_at_ms'] = Variable<int>(updatedAtMs.value);
    }
    if (deletedAtMs.present) {
      map['deleted_at_ms'] = Variable<int>(deletedAtMs.value);
    }
    if (rowid.present) {
      map['rowid'] = Variable<int>(rowid.value);
    }
    return map;
  }

  @override
  String toString() {
    return (StringBuffer('CustomersCompanion(')
          ..write('id: $id, ')
          ..write('name: $name, ')
          ..write('phone: $phone, ')
          ..write('note: $note, ')
          ..write('createdAtMs: $createdAtMs, ')
          ..write('updatedAtMs: $updatedAtMs, ')
          ..write('deletedAtMs: $deletedAtMs, ')
          ..write('rowid: $rowid')
          ..write(')'))
        .toString();
  }
}

class $PaymentProvidersTable extends PaymentProviders
    with TableInfo<$PaymentProvidersTable, PaymentProvider> {
  @override
  final GeneratedDatabase attachedDatabase;
  final String? _alias;
  $PaymentProvidersTable(this.attachedDatabase, [this._alias]);
  static const VerificationMeta _providerKeyMeta = const VerificationMeta(
    'providerKey',
  );
  @override
  late final GeneratedColumn<String> providerKey = GeneratedColumn<String>(
    'provider_key',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _kindMeta = const VerificationMeta('kind');
  @override
  late final GeneratedColumn<String> kind = GeneratedColumn<String>(
    'kind',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _nameMeta = const VerificationMeta('name');
  @override
  late final GeneratedColumn<String> name = GeneratedColumn<String>(
    'name',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _enabledMeta = const VerificationMeta(
    'enabled',
  );
  @override
  late final GeneratedColumn<bool> enabled = GeneratedColumn<bool>(
    'enabled',
    aliasedName,
    false,
    type: DriftSqlType.bool,
    requiredDuringInsert: false,
    defaultConstraints: GeneratedColumn.constraintIsAlways(
      'CHECK ("enabled" IN (0, 1))',
    ),
    defaultValue: const Constant(true),
  );
  static const VerificationMeta _favoriteMeta = const VerificationMeta(
    'favorite',
  );
  @override
  late final GeneratedColumn<bool> favorite = GeneratedColumn<bool>(
    'favorite',
    aliasedName,
    false,
    type: DriftSqlType.bool,
    requiredDuringInsert: false,
    defaultConstraints: GeneratedColumn.constraintIsAlways(
      'CHECK ("favorite" IN (0, 1))',
    ),
    defaultValue: const Constant(false),
  );
  static const VerificationMeta _updatedAtMsMeta = const VerificationMeta(
    'updatedAtMs',
  );
  @override
  late final GeneratedColumn<int> updatedAtMs = GeneratedColumn<int>(
    'updated_at_ms',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: true,
  );
  @override
  List<GeneratedColumn> get $columns => [
    providerKey,
    kind,
    name,
    enabled,
    favorite,
    updatedAtMs,
  ];
  @override
  String get aliasedName => _alias ?? actualTableName;
  @override
  String get actualTableName => $name;
  static const String $name = 'payment_providers';
  @override
  VerificationContext validateIntegrity(
    Insertable<PaymentProvider> instance, {
    bool isInserting = false,
  }) {
    final context = VerificationContext();
    final data = instance.toColumns(true);
    if (data.containsKey('provider_key')) {
      context.handle(
        _providerKeyMeta,
        providerKey.isAcceptableOrUnknown(
          data['provider_key']!,
          _providerKeyMeta,
        ),
      );
    } else if (isInserting) {
      context.missing(_providerKeyMeta);
    }
    if (data.containsKey('kind')) {
      context.handle(
        _kindMeta,
        kind.isAcceptableOrUnknown(data['kind']!, _kindMeta),
      );
    } else if (isInserting) {
      context.missing(_kindMeta);
    }
    if (data.containsKey('name')) {
      context.handle(
        _nameMeta,
        name.isAcceptableOrUnknown(data['name']!, _nameMeta),
      );
    }
    if (data.containsKey('enabled')) {
      context.handle(
        _enabledMeta,
        enabled.isAcceptableOrUnknown(data['enabled']!, _enabledMeta),
      );
    }
    if (data.containsKey('favorite')) {
      context.handle(
        _favoriteMeta,
        favorite.isAcceptableOrUnknown(data['favorite']!, _favoriteMeta),
      );
    }
    if (data.containsKey('updated_at_ms')) {
      context.handle(
        _updatedAtMsMeta,
        updatedAtMs.isAcceptableOrUnknown(
          data['updated_at_ms']!,
          _updatedAtMsMeta,
        ),
      );
    } else if (isInserting) {
      context.missing(_updatedAtMsMeta);
    }
    return context;
  }

  @override
  Set<GeneratedColumn> get $primaryKey => {providerKey};
  @override
  PaymentProvider map(Map<String, dynamic> data, {String? tablePrefix}) {
    final effectivePrefix = tablePrefix != null ? '$tablePrefix.' : '';
    return PaymentProvider(
      providerKey: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}provider_key'],
      )!,
      kind: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}kind'],
      )!,
      name: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}name'],
      ),
      enabled: attachedDatabase.typeMapping.read(
        DriftSqlType.bool,
        data['${effectivePrefix}enabled'],
      )!,
      favorite: attachedDatabase.typeMapping.read(
        DriftSqlType.bool,
        data['${effectivePrefix}favorite'],
      )!,
      updatedAtMs: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}updated_at_ms'],
      )!,
    );
  }

  @override
  $PaymentProvidersTable createAlias(String alias) {
    return $PaymentProvidersTable(attachedDatabase, alias);
  }
}

class PaymentProvider extends DataClass implements Insertable<PaymentProvider> {
  final String providerKey;
  final String kind;
  final String? name;
  final bool enabled;
  final bool favorite;
  final int updatedAtMs;
  const PaymentProvider({
    required this.providerKey,
    required this.kind,
    this.name,
    required this.enabled,
    required this.favorite,
    required this.updatedAtMs,
  });
  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    map['provider_key'] = Variable<String>(providerKey);
    map['kind'] = Variable<String>(kind);
    if (!nullToAbsent || name != null) {
      map['name'] = Variable<String>(name);
    }
    map['enabled'] = Variable<bool>(enabled);
    map['favorite'] = Variable<bool>(favorite);
    map['updated_at_ms'] = Variable<int>(updatedAtMs);
    return map;
  }

  PaymentProvidersCompanion toCompanion(bool nullToAbsent) {
    return PaymentProvidersCompanion(
      providerKey: Value(providerKey),
      kind: Value(kind),
      name: name == null && nullToAbsent ? const Value.absent() : Value(name),
      enabled: Value(enabled),
      favorite: Value(favorite),
      updatedAtMs: Value(updatedAtMs),
    );
  }

  factory PaymentProvider.fromJson(
    Map<String, dynamic> json, {
    ValueSerializer? serializer,
  }) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return PaymentProvider(
      providerKey: serializer.fromJson<String>(json['providerKey']),
      kind: serializer.fromJson<String>(json['kind']),
      name: serializer.fromJson<String?>(json['name']),
      enabled: serializer.fromJson<bool>(json['enabled']),
      favorite: serializer.fromJson<bool>(json['favorite']),
      updatedAtMs: serializer.fromJson<int>(json['updatedAtMs']),
    );
  }
  @override
  Map<String, dynamic> toJson({ValueSerializer? serializer}) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return <String, dynamic>{
      'providerKey': serializer.toJson<String>(providerKey),
      'kind': serializer.toJson<String>(kind),
      'name': serializer.toJson<String?>(name),
      'enabled': serializer.toJson<bool>(enabled),
      'favorite': serializer.toJson<bool>(favorite),
      'updatedAtMs': serializer.toJson<int>(updatedAtMs),
    };
  }

  PaymentProvider copyWith({
    String? providerKey,
    String? kind,
    Value<String?> name = const Value.absent(),
    bool? enabled,
    bool? favorite,
    int? updatedAtMs,
  }) => PaymentProvider(
    providerKey: providerKey ?? this.providerKey,
    kind: kind ?? this.kind,
    name: name.present ? name.value : this.name,
    enabled: enabled ?? this.enabled,
    favorite: favorite ?? this.favorite,
    updatedAtMs: updatedAtMs ?? this.updatedAtMs,
  );
  PaymentProvider copyWithCompanion(PaymentProvidersCompanion data) {
    return PaymentProvider(
      providerKey: data.providerKey.present
          ? data.providerKey.value
          : this.providerKey,
      kind: data.kind.present ? data.kind.value : this.kind,
      name: data.name.present ? data.name.value : this.name,
      enabled: data.enabled.present ? data.enabled.value : this.enabled,
      favorite: data.favorite.present ? data.favorite.value : this.favorite,
      updatedAtMs: data.updatedAtMs.present
          ? data.updatedAtMs.value
          : this.updatedAtMs,
    );
  }

  @override
  String toString() {
    return (StringBuffer('PaymentProvider(')
          ..write('providerKey: $providerKey, ')
          ..write('kind: $kind, ')
          ..write('name: $name, ')
          ..write('enabled: $enabled, ')
          ..write('favorite: $favorite, ')
          ..write('updatedAtMs: $updatedAtMs')
          ..write(')'))
        .toString();
  }

  @override
  int get hashCode =>
      Object.hash(providerKey, kind, name, enabled, favorite, updatedAtMs);
  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      (other is PaymentProvider &&
          other.providerKey == this.providerKey &&
          other.kind == this.kind &&
          other.name == this.name &&
          other.enabled == this.enabled &&
          other.favorite == this.favorite &&
          other.updatedAtMs == this.updatedAtMs);
}

class PaymentProvidersCompanion extends UpdateCompanion<PaymentProvider> {
  final Value<String> providerKey;
  final Value<String> kind;
  final Value<String?> name;
  final Value<bool> enabled;
  final Value<bool> favorite;
  final Value<int> updatedAtMs;
  final Value<int> rowid;
  const PaymentProvidersCompanion({
    this.providerKey = const Value.absent(),
    this.kind = const Value.absent(),
    this.name = const Value.absent(),
    this.enabled = const Value.absent(),
    this.favorite = const Value.absent(),
    this.updatedAtMs = const Value.absent(),
    this.rowid = const Value.absent(),
  });
  PaymentProvidersCompanion.insert({
    required String providerKey,
    required String kind,
    this.name = const Value.absent(),
    this.enabled = const Value.absent(),
    this.favorite = const Value.absent(),
    required int updatedAtMs,
    this.rowid = const Value.absent(),
  }) : providerKey = Value(providerKey),
       kind = Value(kind),
       updatedAtMs = Value(updatedAtMs);
  static Insertable<PaymentProvider> custom({
    Expression<String>? providerKey,
    Expression<String>? kind,
    Expression<String>? name,
    Expression<bool>? enabled,
    Expression<bool>? favorite,
    Expression<int>? updatedAtMs,
    Expression<int>? rowid,
  }) {
    return RawValuesInsertable({
      if (providerKey != null) 'provider_key': providerKey,
      if (kind != null) 'kind': kind,
      if (name != null) 'name': name,
      if (enabled != null) 'enabled': enabled,
      if (favorite != null) 'favorite': favorite,
      if (updatedAtMs != null) 'updated_at_ms': updatedAtMs,
      if (rowid != null) 'rowid': rowid,
    });
  }

  PaymentProvidersCompanion copyWith({
    Value<String>? providerKey,
    Value<String>? kind,
    Value<String?>? name,
    Value<bool>? enabled,
    Value<bool>? favorite,
    Value<int>? updatedAtMs,
    Value<int>? rowid,
  }) {
    return PaymentProvidersCompanion(
      providerKey: providerKey ?? this.providerKey,
      kind: kind ?? this.kind,
      name: name ?? this.name,
      enabled: enabled ?? this.enabled,
      favorite: favorite ?? this.favorite,
      updatedAtMs: updatedAtMs ?? this.updatedAtMs,
      rowid: rowid ?? this.rowid,
    );
  }

  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    if (providerKey.present) {
      map['provider_key'] = Variable<String>(providerKey.value);
    }
    if (kind.present) {
      map['kind'] = Variable<String>(kind.value);
    }
    if (name.present) {
      map['name'] = Variable<String>(name.value);
    }
    if (enabled.present) {
      map['enabled'] = Variable<bool>(enabled.value);
    }
    if (favorite.present) {
      map['favorite'] = Variable<bool>(favorite.value);
    }
    if (updatedAtMs.present) {
      map['updated_at_ms'] = Variable<int>(updatedAtMs.value);
    }
    if (rowid.present) {
      map['rowid'] = Variable<int>(rowid.value);
    }
    return map;
  }

  @override
  String toString() {
    return (StringBuffer('PaymentProvidersCompanion(')
          ..write('providerKey: $providerKey, ')
          ..write('kind: $kind, ')
          ..write('name: $name, ')
          ..write('enabled: $enabled, ')
          ..write('favorite: $favorite, ')
          ..write('updatedAtMs: $updatedAtMs, ')
          ..write('rowid: $rowid')
          ..write(')'))
        .toString();
  }
}

abstract class _$AppDatabase extends GeneratedDatabase {
  _$AppDatabase(QueryExecutor e) : super(e);
  $AppDatabaseManager get managers => $AppDatabaseManager(this);
  late final $ProductsTable products = $ProductsTable(this);
  late final $ProductMaterialsTable productMaterials = $ProductMaterialsTable(
    this,
  );
  late final $MovementsTable movements = $MovementsTable(this);
  late final $OutboxEntriesTable outboxEntries = $OutboxEntriesTable(this);
  late final $SyncMetaEntriesTable syncMetaEntries = $SyncMetaEntriesTable(
    this,
  );
  late final $AppSettingsTable appSettings = $AppSettingsTable(this);
  late final $LocationsTable locations = $LocationsTable(this);
  late final $SalesTable sales = $SalesTable(this);
  late final $PaymentsTable payments = $PaymentsTable(this);
  late final $CustomersTable customers = $CustomersTable(this);
  late final $PaymentProvidersTable paymentProviders = $PaymentProvidersTable(
    this,
  );
  @override
  Iterable<TableInfo<Table, Object?>> get allTables =>
      allSchemaEntities.whereType<TableInfo<Table, Object?>>();
  @override
  List<DatabaseSchemaEntity> get allSchemaEntities => [
    products,
    productMaterials,
    movements,
    outboxEntries,
    syncMetaEntries,
    appSettings,
    locations,
    sales,
    payments,
    customers,
    paymentProviders,
  ];
}

typedef $$ProductsTableCreateCompanionBuilder =
    ProductsCompanion Function({
      required String id,
      required String name,
      Value<String?> sku,
      Value<String> unit,
      Value<String> kind,
      Value<int> costPriceCents,
      Value<int> sellPriceCents,
      Value<bool> askPrice,
      Value<int?> lowStockAtMilli,
      Value<bool> isActive,
      Value<int?> kindChangedAtMs,
      required int updatedAtMs,
      Value<int?> deletedAtMs,
      Value<int> rowid,
    });
typedef $$ProductsTableUpdateCompanionBuilder =
    ProductsCompanion Function({
      Value<String> id,
      Value<String> name,
      Value<String?> sku,
      Value<String> unit,
      Value<String> kind,
      Value<int> costPriceCents,
      Value<int> sellPriceCents,
      Value<bool> askPrice,
      Value<int?> lowStockAtMilli,
      Value<bool> isActive,
      Value<int?> kindChangedAtMs,
      Value<int> updatedAtMs,
      Value<int?> deletedAtMs,
      Value<int> rowid,
    });

class $$ProductsTableFilterComposer
    extends Composer<_$AppDatabase, $ProductsTable> {
  $$ProductsTableFilterComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnFilters<String> get id => $composableBuilder(
    column: $table.id,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get name => $composableBuilder(
    column: $table.name,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get sku => $composableBuilder(
    column: $table.sku,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get unit => $composableBuilder(
    column: $table.unit,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get kind => $composableBuilder(
    column: $table.kind,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get costPriceCents => $composableBuilder(
    column: $table.costPriceCents,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get sellPriceCents => $composableBuilder(
    column: $table.sellPriceCents,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<bool> get askPrice => $composableBuilder(
    column: $table.askPrice,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get lowStockAtMilli => $composableBuilder(
    column: $table.lowStockAtMilli,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<bool> get isActive => $composableBuilder(
    column: $table.isActive,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get kindChangedAtMs => $composableBuilder(
    column: $table.kindChangedAtMs,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get updatedAtMs => $composableBuilder(
    column: $table.updatedAtMs,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get deletedAtMs => $composableBuilder(
    column: $table.deletedAtMs,
    builder: (column) => ColumnFilters(column),
  );
}

class $$ProductsTableOrderingComposer
    extends Composer<_$AppDatabase, $ProductsTable> {
  $$ProductsTableOrderingComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnOrderings<String> get id => $composableBuilder(
    column: $table.id,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get name => $composableBuilder(
    column: $table.name,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get sku => $composableBuilder(
    column: $table.sku,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get unit => $composableBuilder(
    column: $table.unit,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get kind => $composableBuilder(
    column: $table.kind,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get costPriceCents => $composableBuilder(
    column: $table.costPriceCents,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get sellPriceCents => $composableBuilder(
    column: $table.sellPriceCents,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<bool> get askPrice => $composableBuilder(
    column: $table.askPrice,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get lowStockAtMilli => $composableBuilder(
    column: $table.lowStockAtMilli,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<bool> get isActive => $composableBuilder(
    column: $table.isActive,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get kindChangedAtMs => $composableBuilder(
    column: $table.kindChangedAtMs,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get updatedAtMs => $composableBuilder(
    column: $table.updatedAtMs,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get deletedAtMs => $composableBuilder(
    column: $table.deletedAtMs,
    builder: (column) => ColumnOrderings(column),
  );
}

class $$ProductsTableAnnotationComposer
    extends Composer<_$AppDatabase, $ProductsTable> {
  $$ProductsTableAnnotationComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  GeneratedColumn<String> get id =>
      $composableBuilder(column: $table.id, builder: (column) => column);

  GeneratedColumn<String> get name =>
      $composableBuilder(column: $table.name, builder: (column) => column);

  GeneratedColumn<String> get sku =>
      $composableBuilder(column: $table.sku, builder: (column) => column);

  GeneratedColumn<String> get unit =>
      $composableBuilder(column: $table.unit, builder: (column) => column);

  GeneratedColumn<String> get kind =>
      $composableBuilder(column: $table.kind, builder: (column) => column);

  GeneratedColumn<int> get costPriceCents => $composableBuilder(
    column: $table.costPriceCents,
    builder: (column) => column,
  );

  GeneratedColumn<int> get sellPriceCents => $composableBuilder(
    column: $table.sellPriceCents,
    builder: (column) => column,
  );

  GeneratedColumn<bool> get askPrice =>
      $composableBuilder(column: $table.askPrice, builder: (column) => column);

  GeneratedColumn<int> get lowStockAtMilli => $composableBuilder(
    column: $table.lowStockAtMilli,
    builder: (column) => column,
  );

  GeneratedColumn<bool> get isActive =>
      $composableBuilder(column: $table.isActive, builder: (column) => column);

  GeneratedColumn<int> get kindChangedAtMs => $composableBuilder(
    column: $table.kindChangedAtMs,
    builder: (column) => column,
  );

  GeneratedColumn<int> get updatedAtMs => $composableBuilder(
    column: $table.updatedAtMs,
    builder: (column) => column,
  );

  GeneratedColumn<int> get deletedAtMs => $composableBuilder(
    column: $table.deletedAtMs,
    builder: (column) => column,
  );
}

class $$ProductsTableTableManager
    extends
        RootTableManager<
          _$AppDatabase,
          $ProductsTable,
          Product,
          $$ProductsTableFilterComposer,
          $$ProductsTableOrderingComposer,
          $$ProductsTableAnnotationComposer,
          $$ProductsTableCreateCompanionBuilder,
          $$ProductsTableUpdateCompanionBuilder,
          (Product, BaseReferences<_$AppDatabase, $ProductsTable, Product>),
          Product,
          PrefetchHooks Function()
        > {
  $$ProductsTableTableManager(_$AppDatabase db, $ProductsTable table)
    : super(
        TableManagerState(
          db: db,
          table: table,
          createFilteringComposer: () =>
              $$ProductsTableFilterComposer($db: db, $table: table),
          createOrderingComposer: () =>
              $$ProductsTableOrderingComposer($db: db, $table: table),
          createComputedFieldComposer: () =>
              $$ProductsTableAnnotationComposer($db: db, $table: table),
          updateCompanionCallback:
              ({
                Value<String> id = const Value.absent(),
                Value<String> name = const Value.absent(),
                Value<String?> sku = const Value.absent(),
                Value<String> unit = const Value.absent(),
                Value<String> kind = const Value.absent(),
                Value<int> costPriceCents = const Value.absent(),
                Value<int> sellPriceCents = const Value.absent(),
                Value<bool> askPrice = const Value.absent(),
                Value<int?> lowStockAtMilli = const Value.absent(),
                Value<bool> isActive = const Value.absent(),
                Value<int?> kindChangedAtMs = const Value.absent(),
                Value<int> updatedAtMs = const Value.absent(),
                Value<int?> deletedAtMs = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => ProductsCompanion(
                id: id,
                name: name,
                sku: sku,
                unit: unit,
                kind: kind,
                costPriceCents: costPriceCents,
                sellPriceCents: sellPriceCents,
                askPrice: askPrice,
                lowStockAtMilli: lowStockAtMilli,
                isActive: isActive,
                kindChangedAtMs: kindChangedAtMs,
                updatedAtMs: updatedAtMs,
                deletedAtMs: deletedAtMs,
                rowid: rowid,
              ),
          createCompanionCallback:
              ({
                required String id,
                required String name,
                Value<String?> sku = const Value.absent(),
                Value<String> unit = const Value.absent(),
                Value<String> kind = const Value.absent(),
                Value<int> costPriceCents = const Value.absent(),
                Value<int> sellPriceCents = const Value.absent(),
                Value<bool> askPrice = const Value.absent(),
                Value<int?> lowStockAtMilli = const Value.absent(),
                Value<bool> isActive = const Value.absent(),
                Value<int?> kindChangedAtMs = const Value.absent(),
                required int updatedAtMs,
                Value<int?> deletedAtMs = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => ProductsCompanion.insert(
                id: id,
                name: name,
                sku: sku,
                unit: unit,
                kind: kind,
                costPriceCents: costPriceCents,
                sellPriceCents: sellPriceCents,
                askPrice: askPrice,
                lowStockAtMilli: lowStockAtMilli,
                isActive: isActive,
                kindChangedAtMs: kindChangedAtMs,
                updatedAtMs: updatedAtMs,
                deletedAtMs: deletedAtMs,
                rowid: rowid,
              ),
          withReferenceMapper: (p0) => p0
              .map((e) => (e.readTable(table), BaseReferences(db, table, e)))
              .toList(),
          prefetchHooksCallback: null,
        ),
      );
}

typedef $$ProductsTableProcessedTableManager =
    ProcessedTableManager<
      _$AppDatabase,
      $ProductsTable,
      Product,
      $$ProductsTableFilterComposer,
      $$ProductsTableOrderingComposer,
      $$ProductsTableAnnotationComposer,
      $$ProductsTableCreateCompanionBuilder,
      $$ProductsTableUpdateCompanionBuilder,
      (Product, BaseReferences<_$AppDatabase, $ProductsTable, Product>),
      Product,
      PrefetchHooks Function()
    >;
typedef $$ProductMaterialsTableCreateCompanionBuilder =
    ProductMaterialsCompanion Function({
      required String productId,
      required String materialProductId,
      required int qtyMilli,
      Value<int> rowid,
    });
typedef $$ProductMaterialsTableUpdateCompanionBuilder =
    ProductMaterialsCompanion Function({
      Value<String> productId,
      Value<String> materialProductId,
      Value<int> qtyMilli,
      Value<int> rowid,
    });

class $$ProductMaterialsTableFilterComposer
    extends Composer<_$AppDatabase, $ProductMaterialsTable> {
  $$ProductMaterialsTableFilterComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnFilters<String> get productId => $composableBuilder(
    column: $table.productId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get materialProductId => $composableBuilder(
    column: $table.materialProductId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get qtyMilli => $composableBuilder(
    column: $table.qtyMilli,
    builder: (column) => ColumnFilters(column),
  );
}

class $$ProductMaterialsTableOrderingComposer
    extends Composer<_$AppDatabase, $ProductMaterialsTable> {
  $$ProductMaterialsTableOrderingComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnOrderings<String> get productId => $composableBuilder(
    column: $table.productId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get materialProductId => $composableBuilder(
    column: $table.materialProductId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get qtyMilli => $composableBuilder(
    column: $table.qtyMilli,
    builder: (column) => ColumnOrderings(column),
  );
}

class $$ProductMaterialsTableAnnotationComposer
    extends Composer<_$AppDatabase, $ProductMaterialsTable> {
  $$ProductMaterialsTableAnnotationComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  GeneratedColumn<String> get productId =>
      $composableBuilder(column: $table.productId, builder: (column) => column);

  GeneratedColumn<String> get materialProductId => $composableBuilder(
    column: $table.materialProductId,
    builder: (column) => column,
  );

  GeneratedColumn<int> get qtyMilli =>
      $composableBuilder(column: $table.qtyMilli, builder: (column) => column);
}

class $$ProductMaterialsTableTableManager
    extends
        RootTableManager<
          _$AppDatabase,
          $ProductMaterialsTable,
          ProductMaterial,
          $$ProductMaterialsTableFilterComposer,
          $$ProductMaterialsTableOrderingComposer,
          $$ProductMaterialsTableAnnotationComposer,
          $$ProductMaterialsTableCreateCompanionBuilder,
          $$ProductMaterialsTableUpdateCompanionBuilder,
          (
            ProductMaterial,
            BaseReferences<
              _$AppDatabase,
              $ProductMaterialsTable,
              ProductMaterial
            >,
          ),
          ProductMaterial,
          PrefetchHooks Function()
        > {
  $$ProductMaterialsTableTableManager(
    _$AppDatabase db,
    $ProductMaterialsTable table,
  ) : super(
        TableManagerState(
          db: db,
          table: table,
          createFilteringComposer: () =>
              $$ProductMaterialsTableFilterComposer($db: db, $table: table),
          createOrderingComposer: () =>
              $$ProductMaterialsTableOrderingComposer($db: db, $table: table),
          createComputedFieldComposer: () =>
              $$ProductMaterialsTableAnnotationComposer($db: db, $table: table),
          updateCompanionCallback:
              ({
                Value<String> productId = const Value.absent(),
                Value<String> materialProductId = const Value.absent(),
                Value<int> qtyMilli = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => ProductMaterialsCompanion(
                productId: productId,
                materialProductId: materialProductId,
                qtyMilli: qtyMilli,
                rowid: rowid,
              ),
          createCompanionCallback:
              ({
                required String productId,
                required String materialProductId,
                required int qtyMilli,
                Value<int> rowid = const Value.absent(),
              }) => ProductMaterialsCompanion.insert(
                productId: productId,
                materialProductId: materialProductId,
                qtyMilli: qtyMilli,
                rowid: rowid,
              ),
          withReferenceMapper: (p0) => p0
              .map((e) => (e.readTable(table), BaseReferences(db, table, e)))
              .toList(),
          prefetchHooksCallback: null,
        ),
      );
}

typedef $$ProductMaterialsTableProcessedTableManager =
    ProcessedTableManager<
      _$AppDatabase,
      $ProductMaterialsTable,
      ProductMaterial,
      $$ProductMaterialsTableFilterComposer,
      $$ProductMaterialsTableOrderingComposer,
      $$ProductMaterialsTableAnnotationComposer,
      $$ProductMaterialsTableCreateCompanionBuilder,
      $$ProductMaterialsTableUpdateCompanionBuilder,
      (
        ProductMaterial,
        BaseReferences<_$AppDatabase, $ProductMaterialsTable, ProductMaterial>,
      ),
      ProductMaterial,
      PrefetchHooks Function()
    >;
typedef $$MovementsTableCreateCompanionBuilder =
    MovementsCompanion Function({
      required String id,
      required String productId,
      Value<String?> saleId,
      Value<String?> locationId,
      Value<String?> transferId,
      Value<String?> sourceMovementId,
      required String type,
      required int qtyMilli,
      Value<int> unitPriceCents,
      Value<bool> tracksStock,
      Value<String?> note,
      required int createdAtMs,
      Value<int> rowid,
    });
typedef $$MovementsTableUpdateCompanionBuilder =
    MovementsCompanion Function({
      Value<String> id,
      Value<String> productId,
      Value<String?> saleId,
      Value<String?> locationId,
      Value<String?> transferId,
      Value<String?> sourceMovementId,
      Value<String> type,
      Value<int> qtyMilli,
      Value<int> unitPriceCents,
      Value<bool> tracksStock,
      Value<String?> note,
      Value<int> createdAtMs,
      Value<int> rowid,
    });

class $$MovementsTableFilterComposer
    extends Composer<_$AppDatabase, $MovementsTable> {
  $$MovementsTableFilterComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnFilters<String> get id => $composableBuilder(
    column: $table.id,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get productId => $composableBuilder(
    column: $table.productId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get saleId => $composableBuilder(
    column: $table.saleId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get locationId => $composableBuilder(
    column: $table.locationId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get transferId => $composableBuilder(
    column: $table.transferId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get sourceMovementId => $composableBuilder(
    column: $table.sourceMovementId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get type => $composableBuilder(
    column: $table.type,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get qtyMilli => $composableBuilder(
    column: $table.qtyMilli,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get unitPriceCents => $composableBuilder(
    column: $table.unitPriceCents,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<bool> get tracksStock => $composableBuilder(
    column: $table.tracksStock,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get note => $composableBuilder(
    column: $table.note,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get createdAtMs => $composableBuilder(
    column: $table.createdAtMs,
    builder: (column) => ColumnFilters(column),
  );
}

class $$MovementsTableOrderingComposer
    extends Composer<_$AppDatabase, $MovementsTable> {
  $$MovementsTableOrderingComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnOrderings<String> get id => $composableBuilder(
    column: $table.id,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get productId => $composableBuilder(
    column: $table.productId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get saleId => $composableBuilder(
    column: $table.saleId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get locationId => $composableBuilder(
    column: $table.locationId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get transferId => $composableBuilder(
    column: $table.transferId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get sourceMovementId => $composableBuilder(
    column: $table.sourceMovementId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get type => $composableBuilder(
    column: $table.type,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get qtyMilli => $composableBuilder(
    column: $table.qtyMilli,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get unitPriceCents => $composableBuilder(
    column: $table.unitPriceCents,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<bool> get tracksStock => $composableBuilder(
    column: $table.tracksStock,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get note => $composableBuilder(
    column: $table.note,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get createdAtMs => $composableBuilder(
    column: $table.createdAtMs,
    builder: (column) => ColumnOrderings(column),
  );
}

class $$MovementsTableAnnotationComposer
    extends Composer<_$AppDatabase, $MovementsTable> {
  $$MovementsTableAnnotationComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  GeneratedColumn<String> get id =>
      $composableBuilder(column: $table.id, builder: (column) => column);

  GeneratedColumn<String> get productId =>
      $composableBuilder(column: $table.productId, builder: (column) => column);

  GeneratedColumn<String> get saleId =>
      $composableBuilder(column: $table.saleId, builder: (column) => column);

  GeneratedColumn<String> get locationId => $composableBuilder(
    column: $table.locationId,
    builder: (column) => column,
  );

  GeneratedColumn<String> get transferId => $composableBuilder(
    column: $table.transferId,
    builder: (column) => column,
  );

  GeneratedColumn<String> get sourceMovementId => $composableBuilder(
    column: $table.sourceMovementId,
    builder: (column) => column,
  );

  GeneratedColumn<String> get type =>
      $composableBuilder(column: $table.type, builder: (column) => column);

  GeneratedColumn<int> get qtyMilli =>
      $composableBuilder(column: $table.qtyMilli, builder: (column) => column);

  GeneratedColumn<int> get unitPriceCents => $composableBuilder(
    column: $table.unitPriceCents,
    builder: (column) => column,
  );

  GeneratedColumn<bool> get tracksStock => $composableBuilder(
    column: $table.tracksStock,
    builder: (column) => column,
  );

  GeneratedColumn<String> get note =>
      $composableBuilder(column: $table.note, builder: (column) => column);

  GeneratedColumn<int> get createdAtMs => $composableBuilder(
    column: $table.createdAtMs,
    builder: (column) => column,
  );
}

class $$MovementsTableTableManager
    extends
        RootTableManager<
          _$AppDatabase,
          $MovementsTable,
          Movement,
          $$MovementsTableFilterComposer,
          $$MovementsTableOrderingComposer,
          $$MovementsTableAnnotationComposer,
          $$MovementsTableCreateCompanionBuilder,
          $$MovementsTableUpdateCompanionBuilder,
          (Movement, BaseReferences<_$AppDatabase, $MovementsTable, Movement>),
          Movement,
          PrefetchHooks Function()
        > {
  $$MovementsTableTableManager(_$AppDatabase db, $MovementsTable table)
    : super(
        TableManagerState(
          db: db,
          table: table,
          createFilteringComposer: () =>
              $$MovementsTableFilterComposer($db: db, $table: table),
          createOrderingComposer: () =>
              $$MovementsTableOrderingComposer($db: db, $table: table),
          createComputedFieldComposer: () =>
              $$MovementsTableAnnotationComposer($db: db, $table: table),
          updateCompanionCallback:
              ({
                Value<String> id = const Value.absent(),
                Value<String> productId = const Value.absent(),
                Value<String?> saleId = const Value.absent(),
                Value<String?> locationId = const Value.absent(),
                Value<String?> transferId = const Value.absent(),
                Value<String?> sourceMovementId = const Value.absent(),
                Value<String> type = const Value.absent(),
                Value<int> qtyMilli = const Value.absent(),
                Value<int> unitPriceCents = const Value.absent(),
                Value<bool> tracksStock = const Value.absent(),
                Value<String?> note = const Value.absent(),
                Value<int> createdAtMs = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => MovementsCompanion(
                id: id,
                productId: productId,
                saleId: saleId,
                locationId: locationId,
                transferId: transferId,
                sourceMovementId: sourceMovementId,
                type: type,
                qtyMilli: qtyMilli,
                unitPriceCents: unitPriceCents,
                tracksStock: tracksStock,
                note: note,
                createdAtMs: createdAtMs,
                rowid: rowid,
              ),
          createCompanionCallback:
              ({
                required String id,
                required String productId,
                Value<String?> saleId = const Value.absent(),
                Value<String?> locationId = const Value.absent(),
                Value<String?> transferId = const Value.absent(),
                Value<String?> sourceMovementId = const Value.absent(),
                required String type,
                required int qtyMilli,
                Value<int> unitPriceCents = const Value.absent(),
                Value<bool> tracksStock = const Value.absent(),
                Value<String?> note = const Value.absent(),
                required int createdAtMs,
                Value<int> rowid = const Value.absent(),
              }) => MovementsCompanion.insert(
                id: id,
                productId: productId,
                saleId: saleId,
                locationId: locationId,
                transferId: transferId,
                sourceMovementId: sourceMovementId,
                type: type,
                qtyMilli: qtyMilli,
                unitPriceCents: unitPriceCents,
                tracksStock: tracksStock,
                note: note,
                createdAtMs: createdAtMs,
                rowid: rowid,
              ),
          withReferenceMapper: (p0) => p0
              .map((e) => (e.readTable(table), BaseReferences(db, table, e)))
              .toList(),
          prefetchHooksCallback: null,
        ),
      );
}

typedef $$MovementsTableProcessedTableManager =
    ProcessedTableManager<
      _$AppDatabase,
      $MovementsTable,
      Movement,
      $$MovementsTableFilterComposer,
      $$MovementsTableOrderingComposer,
      $$MovementsTableAnnotationComposer,
      $$MovementsTableCreateCompanionBuilder,
      $$MovementsTableUpdateCompanionBuilder,
      (Movement, BaseReferences<_$AppDatabase, $MovementsTable, Movement>),
      Movement,
      PrefetchHooks Function()
    >;
typedef $$OutboxEntriesTableCreateCompanionBuilder =
    OutboxEntriesCompanion Function({
      required String id,
      required String entity,
      required String entityId,
      required String op,
      required String payloadJson,
      required int clientTs,
      Value<String> status,
      Value<int> retryCount,
      Value<String?> errorReason,
      required int createdAt,
      Value<int> rowid,
    });
typedef $$OutboxEntriesTableUpdateCompanionBuilder =
    OutboxEntriesCompanion Function({
      Value<String> id,
      Value<String> entity,
      Value<String> entityId,
      Value<String> op,
      Value<String> payloadJson,
      Value<int> clientTs,
      Value<String> status,
      Value<int> retryCount,
      Value<String?> errorReason,
      Value<int> createdAt,
      Value<int> rowid,
    });

class $$OutboxEntriesTableFilterComposer
    extends Composer<_$AppDatabase, $OutboxEntriesTable> {
  $$OutboxEntriesTableFilterComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnFilters<String> get id => $composableBuilder(
    column: $table.id,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get entity => $composableBuilder(
    column: $table.entity,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get entityId => $composableBuilder(
    column: $table.entityId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get op => $composableBuilder(
    column: $table.op,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get payloadJson => $composableBuilder(
    column: $table.payloadJson,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get clientTs => $composableBuilder(
    column: $table.clientTs,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get status => $composableBuilder(
    column: $table.status,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get retryCount => $composableBuilder(
    column: $table.retryCount,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get errorReason => $composableBuilder(
    column: $table.errorReason,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get createdAt => $composableBuilder(
    column: $table.createdAt,
    builder: (column) => ColumnFilters(column),
  );
}

class $$OutboxEntriesTableOrderingComposer
    extends Composer<_$AppDatabase, $OutboxEntriesTable> {
  $$OutboxEntriesTableOrderingComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnOrderings<String> get id => $composableBuilder(
    column: $table.id,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get entity => $composableBuilder(
    column: $table.entity,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get entityId => $composableBuilder(
    column: $table.entityId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get op => $composableBuilder(
    column: $table.op,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get payloadJson => $composableBuilder(
    column: $table.payloadJson,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get clientTs => $composableBuilder(
    column: $table.clientTs,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get status => $composableBuilder(
    column: $table.status,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get retryCount => $composableBuilder(
    column: $table.retryCount,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get errorReason => $composableBuilder(
    column: $table.errorReason,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get createdAt => $composableBuilder(
    column: $table.createdAt,
    builder: (column) => ColumnOrderings(column),
  );
}

class $$OutboxEntriesTableAnnotationComposer
    extends Composer<_$AppDatabase, $OutboxEntriesTable> {
  $$OutboxEntriesTableAnnotationComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  GeneratedColumn<String> get id =>
      $composableBuilder(column: $table.id, builder: (column) => column);

  GeneratedColumn<String> get entity =>
      $composableBuilder(column: $table.entity, builder: (column) => column);

  GeneratedColumn<String> get entityId =>
      $composableBuilder(column: $table.entityId, builder: (column) => column);

  GeneratedColumn<String> get op =>
      $composableBuilder(column: $table.op, builder: (column) => column);

  GeneratedColumn<String> get payloadJson => $composableBuilder(
    column: $table.payloadJson,
    builder: (column) => column,
  );

  GeneratedColumn<int> get clientTs =>
      $composableBuilder(column: $table.clientTs, builder: (column) => column);

  GeneratedColumn<String> get status =>
      $composableBuilder(column: $table.status, builder: (column) => column);

  GeneratedColumn<int> get retryCount => $composableBuilder(
    column: $table.retryCount,
    builder: (column) => column,
  );

  GeneratedColumn<String> get errorReason => $composableBuilder(
    column: $table.errorReason,
    builder: (column) => column,
  );

  GeneratedColumn<int> get createdAt =>
      $composableBuilder(column: $table.createdAt, builder: (column) => column);
}

class $$OutboxEntriesTableTableManager
    extends
        RootTableManager<
          _$AppDatabase,
          $OutboxEntriesTable,
          OutboxEntry,
          $$OutboxEntriesTableFilterComposer,
          $$OutboxEntriesTableOrderingComposer,
          $$OutboxEntriesTableAnnotationComposer,
          $$OutboxEntriesTableCreateCompanionBuilder,
          $$OutboxEntriesTableUpdateCompanionBuilder,
          (
            OutboxEntry,
            BaseReferences<_$AppDatabase, $OutboxEntriesTable, OutboxEntry>,
          ),
          OutboxEntry,
          PrefetchHooks Function()
        > {
  $$OutboxEntriesTableTableManager(_$AppDatabase db, $OutboxEntriesTable table)
    : super(
        TableManagerState(
          db: db,
          table: table,
          createFilteringComposer: () =>
              $$OutboxEntriesTableFilterComposer($db: db, $table: table),
          createOrderingComposer: () =>
              $$OutboxEntriesTableOrderingComposer($db: db, $table: table),
          createComputedFieldComposer: () =>
              $$OutboxEntriesTableAnnotationComposer($db: db, $table: table),
          updateCompanionCallback:
              ({
                Value<String> id = const Value.absent(),
                Value<String> entity = const Value.absent(),
                Value<String> entityId = const Value.absent(),
                Value<String> op = const Value.absent(),
                Value<String> payloadJson = const Value.absent(),
                Value<int> clientTs = const Value.absent(),
                Value<String> status = const Value.absent(),
                Value<int> retryCount = const Value.absent(),
                Value<String?> errorReason = const Value.absent(),
                Value<int> createdAt = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => OutboxEntriesCompanion(
                id: id,
                entity: entity,
                entityId: entityId,
                op: op,
                payloadJson: payloadJson,
                clientTs: clientTs,
                status: status,
                retryCount: retryCount,
                errorReason: errorReason,
                createdAt: createdAt,
                rowid: rowid,
              ),
          createCompanionCallback:
              ({
                required String id,
                required String entity,
                required String entityId,
                required String op,
                required String payloadJson,
                required int clientTs,
                Value<String> status = const Value.absent(),
                Value<int> retryCount = const Value.absent(),
                Value<String?> errorReason = const Value.absent(),
                required int createdAt,
                Value<int> rowid = const Value.absent(),
              }) => OutboxEntriesCompanion.insert(
                id: id,
                entity: entity,
                entityId: entityId,
                op: op,
                payloadJson: payloadJson,
                clientTs: clientTs,
                status: status,
                retryCount: retryCount,
                errorReason: errorReason,
                createdAt: createdAt,
                rowid: rowid,
              ),
          withReferenceMapper: (p0) => p0
              .map((e) => (e.readTable(table), BaseReferences(db, table, e)))
              .toList(),
          prefetchHooksCallback: null,
        ),
      );
}

typedef $$OutboxEntriesTableProcessedTableManager =
    ProcessedTableManager<
      _$AppDatabase,
      $OutboxEntriesTable,
      OutboxEntry,
      $$OutboxEntriesTableFilterComposer,
      $$OutboxEntriesTableOrderingComposer,
      $$OutboxEntriesTableAnnotationComposer,
      $$OutboxEntriesTableCreateCompanionBuilder,
      $$OutboxEntriesTableUpdateCompanionBuilder,
      (
        OutboxEntry,
        BaseReferences<_$AppDatabase, $OutboxEntriesTable, OutboxEntry>,
      ),
      OutboxEntry,
      PrefetchHooks Function()
    >;
typedef $$SyncMetaEntriesTableCreateCompanionBuilder =
    SyncMetaEntriesCompanion Function({
      required String key,
      required String value,
      Value<int> rowid,
    });
typedef $$SyncMetaEntriesTableUpdateCompanionBuilder =
    SyncMetaEntriesCompanion Function({
      Value<String> key,
      Value<String> value,
      Value<int> rowid,
    });

class $$SyncMetaEntriesTableFilterComposer
    extends Composer<_$AppDatabase, $SyncMetaEntriesTable> {
  $$SyncMetaEntriesTableFilterComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnFilters<String> get key => $composableBuilder(
    column: $table.key,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get value => $composableBuilder(
    column: $table.value,
    builder: (column) => ColumnFilters(column),
  );
}

class $$SyncMetaEntriesTableOrderingComposer
    extends Composer<_$AppDatabase, $SyncMetaEntriesTable> {
  $$SyncMetaEntriesTableOrderingComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnOrderings<String> get key => $composableBuilder(
    column: $table.key,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get value => $composableBuilder(
    column: $table.value,
    builder: (column) => ColumnOrderings(column),
  );
}

class $$SyncMetaEntriesTableAnnotationComposer
    extends Composer<_$AppDatabase, $SyncMetaEntriesTable> {
  $$SyncMetaEntriesTableAnnotationComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  GeneratedColumn<String> get key =>
      $composableBuilder(column: $table.key, builder: (column) => column);

  GeneratedColumn<String> get value =>
      $composableBuilder(column: $table.value, builder: (column) => column);
}

class $$SyncMetaEntriesTableTableManager
    extends
        RootTableManager<
          _$AppDatabase,
          $SyncMetaEntriesTable,
          SyncMetaEntry,
          $$SyncMetaEntriesTableFilterComposer,
          $$SyncMetaEntriesTableOrderingComposer,
          $$SyncMetaEntriesTableAnnotationComposer,
          $$SyncMetaEntriesTableCreateCompanionBuilder,
          $$SyncMetaEntriesTableUpdateCompanionBuilder,
          (
            SyncMetaEntry,
            BaseReferences<_$AppDatabase, $SyncMetaEntriesTable, SyncMetaEntry>,
          ),
          SyncMetaEntry,
          PrefetchHooks Function()
        > {
  $$SyncMetaEntriesTableTableManager(
    _$AppDatabase db,
    $SyncMetaEntriesTable table,
  ) : super(
        TableManagerState(
          db: db,
          table: table,
          createFilteringComposer: () =>
              $$SyncMetaEntriesTableFilterComposer($db: db, $table: table),
          createOrderingComposer: () =>
              $$SyncMetaEntriesTableOrderingComposer($db: db, $table: table),
          createComputedFieldComposer: () =>
              $$SyncMetaEntriesTableAnnotationComposer($db: db, $table: table),
          updateCompanionCallback:
              ({
                Value<String> key = const Value.absent(),
                Value<String> value = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => SyncMetaEntriesCompanion(
                key: key,
                value: value,
                rowid: rowid,
              ),
          createCompanionCallback:
              ({
                required String key,
                required String value,
                Value<int> rowid = const Value.absent(),
              }) => SyncMetaEntriesCompanion.insert(
                key: key,
                value: value,
                rowid: rowid,
              ),
          withReferenceMapper: (p0) => p0
              .map((e) => (e.readTable(table), BaseReferences(db, table, e)))
              .toList(),
          prefetchHooksCallback: null,
        ),
      );
}

typedef $$SyncMetaEntriesTableProcessedTableManager =
    ProcessedTableManager<
      _$AppDatabase,
      $SyncMetaEntriesTable,
      SyncMetaEntry,
      $$SyncMetaEntriesTableFilterComposer,
      $$SyncMetaEntriesTableOrderingComposer,
      $$SyncMetaEntriesTableAnnotationComposer,
      $$SyncMetaEntriesTableCreateCompanionBuilder,
      $$SyncMetaEntriesTableUpdateCompanionBuilder,
      (
        SyncMetaEntry,
        BaseReferences<_$AppDatabase, $SyncMetaEntriesTable, SyncMetaEntry>,
      ),
      SyncMetaEntry,
      PrefetchHooks Function()
    >;
typedef $$AppSettingsTableCreateCompanionBuilder =
    AppSettingsCompanion Function({
      required String key,
      required String value,
      Value<int> rowid,
    });
typedef $$AppSettingsTableUpdateCompanionBuilder =
    AppSettingsCompanion Function({
      Value<String> key,
      Value<String> value,
      Value<int> rowid,
    });

class $$AppSettingsTableFilterComposer
    extends Composer<_$AppDatabase, $AppSettingsTable> {
  $$AppSettingsTableFilterComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnFilters<String> get key => $composableBuilder(
    column: $table.key,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get value => $composableBuilder(
    column: $table.value,
    builder: (column) => ColumnFilters(column),
  );
}

class $$AppSettingsTableOrderingComposer
    extends Composer<_$AppDatabase, $AppSettingsTable> {
  $$AppSettingsTableOrderingComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnOrderings<String> get key => $composableBuilder(
    column: $table.key,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get value => $composableBuilder(
    column: $table.value,
    builder: (column) => ColumnOrderings(column),
  );
}

class $$AppSettingsTableAnnotationComposer
    extends Composer<_$AppDatabase, $AppSettingsTable> {
  $$AppSettingsTableAnnotationComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  GeneratedColumn<String> get key =>
      $composableBuilder(column: $table.key, builder: (column) => column);

  GeneratedColumn<String> get value =>
      $composableBuilder(column: $table.value, builder: (column) => column);
}

class $$AppSettingsTableTableManager
    extends
        RootTableManager<
          _$AppDatabase,
          $AppSettingsTable,
          AppSetting,
          $$AppSettingsTableFilterComposer,
          $$AppSettingsTableOrderingComposer,
          $$AppSettingsTableAnnotationComposer,
          $$AppSettingsTableCreateCompanionBuilder,
          $$AppSettingsTableUpdateCompanionBuilder,
          (
            AppSetting,
            BaseReferences<_$AppDatabase, $AppSettingsTable, AppSetting>,
          ),
          AppSetting,
          PrefetchHooks Function()
        > {
  $$AppSettingsTableTableManager(_$AppDatabase db, $AppSettingsTable table)
    : super(
        TableManagerState(
          db: db,
          table: table,
          createFilteringComposer: () =>
              $$AppSettingsTableFilterComposer($db: db, $table: table),
          createOrderingComposer: () =>
              $$AppSettingsTableOrderingComposer($db: db, $table: table),
          createComputedFieldComposer: () =>
              $$AppSettingsTableAnnotationComposer($db: db, $table: table),
          updateCompanionCallback:
              ({
                Value<String> key = const Value.absent(),
                Value<String> value = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => AppSettingsCompanion(key: key, value: value, rowid: rowid),
          createCompanionCallback:
              ({
                required String key,
                required String value,
                Value<int> rowid = const Value.absent(),
              }) => AppSettingsCompanion.insert(
                key: key,
                value: value,
                rowid: rowid,
              ),
          withReferenceMapper: (p0) => p0
              .map((e) => (e.readTable(table), BaseReferences(db, table, e)))
              .toList(),
          prefetchHooksCallback: null,
        ),
      );
}

typedef $$AppSettingsTableProcessedTableManager =
    ProcessedTableManager<
      _$AppDatabase,
      $AppSettingsTable,
      AppSetting,
      $$AppSettingsTableFilterComposer,
      $$AppSettingsTableOrderingComposer,
      $$AppSettingsTableAnnotationComposer,
      $$AppSettingsTableCreateCompanionBuilder,
      $$AppSettingsTableUpdateCompanionBuilder,
      (
        AppSetting,
        BaseReferences<_$AppDatabase, $AppSettingsTable, AppSetting>,
      ),
      AppSetting,
      PrefetchHooks Function()
    >;
typedef $$LocationsTableCreateCompanionBuilder =
    LocationsCompanion Function({
      required String id,
      required String name,
      Value<String> kind,
      Value<bool> isDefault,
      Value<bool> isActive,
      required int updatedAtMs,
      Value<int> rowid,
    });
typedef $$LocationsTableUpdateCompanionBuilder =
    LocationsCompanion Function({
      Value<String> id,
      Value<String> name,
      Value<String> kind,
      Value<bool> isDefault,
      Value<bool> isActive,
      Value<int> updatedAtMs,
      Value<int> rowid,
    });

class $$LocationsTableFilterComposer
    extends Composer<_$AppDatabase, $LocationsTable> {
  $$LocationsTableFilterComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnFilters<String> get id => $composableBuilder(
    column: $table.id,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get name => $composableBuilder(
    column: $table.name,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get kind => $composableBuilder(
    column: $table.kind,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<bool> get isDefault => $composableBuilder(
    column: $table.isDefault,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<bool> get isActive => $composableBuilder(
    column: $table.isActive,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get updatedAtMs => $composableBuilder(
    column: $table.updatedAtMs,
    builder: (column) => ColumnFilters(column),
  );
}

class $$LocationsTableOrderingComposer
    extends Composer<_$AppDatabase, $LocationsTable> {
  $$LocationsTableOrderingComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnOrderings<String> get id => $composableBuilder(
    column: $table.id,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get name => $composableBuilder(
    column: $table.name,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get kind => $composableBuilder(
    column: $table.kind,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<bool> get isDefault => $composableBuilder(
    column: $table.isDefault,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<bool> get isActive => $composableBuilder(
    column: $table.isActive,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get updatedAtMs => $composableBuilder(
    column: $table.updatedAtMs,
    builder: (column) => ColumnOrderings(column),
  );
}

class $$LocationsTableAnnotationComposer
    extends Composer<_$AppDatabase, $LocationsTable> {
  $$LocationsTableAnnotationComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  GeneratedColumn<String> get id =>
      $composableBuilder(column: $table.id, builder: (column) => column);

  GeneratedColumn<String> get name =>
      $composableBuilder(column: $table.name, builder: (column) => column);

  GeneratedColumn<String> get kind =>
      $composableBuilder(column: $table.kind, builder: (column) => column);

  GeneratedColumn<bool> get isDefault =>
      $composableBuilder(column: $table.isDefault, builder: (column) => column);

  GeneratedColumn<bool> get isActive =>
      $composableBuilder(column: $table.isActive, builder: (column) => column);

  GeneratedColumn<int> get updatedAtMs => $composableBuilder(
    column: $table.updatedAtMs,
    builder: (column) => column,
  );
}

class $$LocationsTableTableManager
    extends
        RootTableManager<
          _$AppDatabase,
          $LocationsTable,
          Location,
          $$LocationsTableFilterComposer,
          $$LocationsTableOrderingComposer,
          $$LocationsTableAnnotationComposer,
          $$LocationsTableCreateCompanionBuilder,
          $$LocationsTableUpdateCompanionBuilder,
          (Location, BaseReferences<_$AppDatabase, $LocationsTable, Location>),
          Location,
          PrefetchHooks Function()
        > {
  $$LocationsTableTableManager(_$AppDatabase db, $LocationsTable table)
    : super(
        TableManagerState(
          db: db,
          table: table,
          createFilteringComposer: () =>
              $$LocationsTableFilterComposer($db: db, $table: table),
          createOrderingComposer: () =>
              $$LocationsTableOrderingComposer($db: db, $table: table),
          createComputedFieldComposer: () =>
              $$LocationsTableAnnotationComposer($db: db, $table: table),
          updateCompanionCallback:
              ({
                Value<String> id = const Value.absent(),
                Value<String> name = const Value.absent(),
                Value<String> kind = const Value.absent(),
                Value<bool> isDefault = const Value.absent(),
                Value<bool> isActive = const Value.absent(),
                Value<int> updatedAtMs = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => LocationsCompanion(
                id: id,
                name: name,
                kind: kind,
                isDefault: isDefault,
                isActive: isActive,
                updatedAtMs: updatedAtMs,
                rowid: rowid,
              ),
          createCompanionCallback:
              ({
                required String id,
                required String name,
                Value<String> kind = const Value.absent(),
                Value<bool> isDefault = const Value.absent(),
                Value<bool> isActive = const Value.absent(),
                required int updatedAtMs,
                Value<int> rowid = const Value.absent(),
              }) => LocationsCompanion.insert(
                id: id,
                name: name,
                kind: kind,
                isDefault: isDefault,
                isActive: isActive,
                updatedAtMs: updatedAtMs,
                rowid: rowid,
              ),
          withReferenceMapper: (p0) => p0
              .map((e) => (e.readTable(table), BaseReferences(db, table, e)))
              .toList(),
          prefetchHooksCallback: null,
        ),
      );
}

typedef $$LocationsTableProcessedTableManager =
    ProcessedTableManager<
      _$AppDatabase,
      $LocationsTable,
      Location,
      $$LocationsTableFilterComposer,
      $$LocationsTableOrderingComposer,
      $$LocationsTableAnnotationComposer,
      $$LocationsTableCreateCompanionBuilder,
      $$LocationsTableUpdateCompanionBuilder,
      (Location, BaseReferences<_$AppDatabase, $LocationsTable, Location>),
      Location,
      PrefetchHooks Function()
    >;
typedef $$SalesTableCreateCompanionBuilder =
    SalesCompanion Function({
      required String id,
      Value<String?> locationId,
      Value<String?> customerId,
      required int totalCents,
      Value<String?> dueDate,
      Value<String?> note,
      Value<bool> legacy,
      Value<int?> voidedAtMs,
      required int createdAtMs,
      required int updatedAtMs,
      Value<int> rowid,
    });
typedef $$SalesTableUpdateCompanionBuilder =
    SalesCompanion Function({
      Value<String> id,
      Value<String?> locationId,
      Value<String?> customerId,
      Value<int> totalCents,
      Value<String?> dueDate,
      Value<String?> note,
      Value<bool> legacy,
      Value<int?> voidedAtMs,
      Value<int> createdAtMs,
      Value<int> updatedAtMs,
      Value<int> rowid,
    });

class $$SalesTableFilterComposer extends Composer<_$AppDatabase, $SalesTable> {
  $$SalesTableFilterComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnFilters<String> get id => $composableBuilder(
    column: $table.id,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get locationId => $composableBuilder(
    column: $table.locationId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get customerId => $composableBuilder(
    column: $table.customerId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get totalCents => $composableBuilder(
    column: $table.totalCents,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get dueDate => $composableBuilder(
    column: $table.dueDate,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get note => $composableBuilder(
    column: $table.note,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<bool> get legacy => $composableBuilder(
    column: $table.legacy,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get voidedAtMs => $composableBuilder(
    column: $table.voidedAtMs,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get createdAtMs => $composableBuilder(
    column: $table.createdAtMs,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get updatedAtMs => $composableBuilder(
    column: $table.updatedAtMs,
    builder: (column) => ColumnFilters(column),
  );
}

class $$SalesTableOrderingComposer
    extends Composer<_$AppDatabase, $SalesTable> {
  $$SalesTableOrderingComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnOrderings<String> get id => $composableBuilder(
    column: $table.id,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get locationId => $composableBuilder(
    column: $table.locationId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get customerId => $composableBuilder(
    column: $table.customerId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get totalCents => $composableBuilder(
    column: $table.totalCents,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get dueDate => $composableBuilder(
    column: $table.dueDate,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get note => $composableBuilder(
    column: $table.note,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<bool> get legacy => $composableBuilder(
    column: $table.legacy,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get voidedAtMs => $composableBuilder(
    column: $table.voidedAtMs,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get createdAtMs => $composableBuilder(
    column: $table.createdAtMs,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get updatedAtMs => $composableBuilder(
    column: $table.updatedAtMs,
    builder: (column) => ColumnOrderings(column),
  );
}

class $$SalesTableAnnotationComposer
    extends Composer<_$AppDatabase, $SalesTable> {
  $$SalesTableAnnotationComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  GeneratedColumn<String> get id =>
      $composableBuilder(column: $table.id, builder: (column) => column);

  GeneratedColumn<String> get locationId => $composableBuilder(
    column: $table.locationId,
    builder: (column) => column,
  );

  GeneratedColumn<String> get customerId => $composableBuilder(
    column: $table.customerId,
    builder: (column) => column,
  );

  GeneratedColumn<int> get totalCents => $composableBuilder(
    column: $table.totalCents,
    builder: (column) => column,
  );

  GeneratedColumn<String> get dueDate =>
      $composableBuilder(column: $table.dueDate, builder: (column) => column);

  GeneratedColumn<String> get note =>
      $composableBuilder(column: $table.note, builder: (column) => column);

  GeneratedColumn<bool> get legacy =>
      $composableBuilder(column: $table.legacy, builder: (column) => column);

  GeneratedColumn<int> get voidedAtMs => $composableBuilder(
    column: $table.voidedAtMs,
    builder: (column) => column,
  );

  GeneratedColumn<int> get createdAtMs => $composableBuilder(
    column: $table.createdAtMs,
    builder: (column) => column,
  );

  GeneratedColumn<int> get updatedAtMs => $composableBuilder(
    column: $table.updatedAtMs,
    builder: (column) => column,
  );
}

class $$SalesTableTableManager
    extends
        RootTableManager<
          _$AppDatabase,
          $SalesTable,
          Sale,
          $$SalesTableFilterComposer,
          $$SalesTableOrderingComposer,
          $$SalesTableAnnotationComposer,
          $$SalesTableCreateCompanionBuilder,
          $$SalesTableUpdateCompanionBuilder,
          (Sale, BaseReferences<_$AppDatabase, $SalesTable, Sale>),
          Sale,
          PrefetchHooks Function()
        > {
  $$SalesTableTableManager(_$AppDatabase db, $SalesTable table)
    : super(
        TableManagerState(
          db: db,
          table: table,
          createFilteringComposer: () =>
              $$SalesTableFilterComposer($db: db, $table: table),
          createOrderingComposer: () =>
              $$SalesTableOrderingComposer($db: db, $table: table),
          createComputedFieldComposer: () =>
              $$SalesTableAnnotationComposer($db: db, $table: table),
          updateCompanionCallback:
              ({
                Value<String> id = const Value.absent(),
                Value<String?> locationId = const Value.absent(),
                Value<String?> customerId = const Value.absent(),
                Value<int> totalCents = const Value.absent(),
                Value<String?> dueDate = const Value.absent(),
                Value<String?> note = const Value.absent(),
                Value<bool> legacy = const Value.absent(),
                Value<int?> voidedAtMs = const Value.absent(),
                Value<int> createdAtMs = const Value.absent(),
                Value<int> updatedAtMs = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => SalesCompanion(
                id: id,
                locationId: locationId,
                customerId: customerId,
                totalCents: totalCents,
                dueDate: dueDate,
                note: note,
                legacy: legacy,
                voidedAtMs: voidedAtMs,
                createdAtMs: createdAtMs,
                updatedAtMs: updatedAtMs,
                rowid: rowid,
              ),
          createCompanionCallback:
              ({
                required String id,
                Value<String?> locationId = const Value.absent(),
                Value<String?> customerId = const Value.absent(),
                required int totalCents,
                Value<String?> dueDate = const Value.absent(),
                Value<String?> note = const Value.absent(),
                Value<bool> legacy = const Value.absent(),
                Value<int?> voidedAtMs = const Value.absent(),
                required int createdAtMs,
                required int updatedAtMs,
                Value<int> rowid = const Value.absent(),
              }) => SalesCompanion.insert(
                id: id,
                locationId: locationId,
                customerId: customerId,
                totalCents: totalCents,
                dueDate: dueDate,
                note: note,
                legacy: legacy,
                voidedAtMs: voidedAtMs,
                createdAtMs: createdAtMs,
                updatedAtMs: updatedAtMs,
                rowid: rowid,
              ),
          withReferenceMapper: (p0) => p0
              .map((e) => (e.readTable(table), BaseReferences(db, table, e)))
              .toList(),
          prefetchHooksCallback: null,
        ),
      );
}

typedef $$SalesTableProcessedTableManager =
    ProcessedTableManager<
      _$AppDatabase,
      $SalesTable,
      Sale,
      $$SalesTableFilterComposer,
      $$SalesTableOrderingComposer,
      $$SalesTableAnnotationComposer,
      $$SalesTableCreateCompanionBuilder,
      $$SalesTableUpdateCompanionBuilder,
      (Sale, BaseReferences<_$AppDatabase, $SalesTable, Sale>),
      Sale,
      PrefetchHooks Function()
    >;
typedef $$PaymentsTableCreateCompanionBuilder =
    PaymentsCompanion Function({
      required String id,
      Value<String?> saleId,
      Value<String?> customerId,
      Value<String?> locationId,
      required String method,
      Value<String?> providerKey,
      required int amountCents,
      Value<String?> reference,
      required int receivedAtMs,
      required int createdAtMs,
      Value<int> rowid,
    });
typedef $$PaymentsTableUpdateCompanionBuilder =
    PaymentsCompanion Function({
      Value<String> id,
      Value<String?> saleId,
      Value<String?> customerId,
      Value<String?> locationId,
      Value<String> method,
      Value<String?> providerKey,
      Value<int> amountCents,
      Value<String?> reference,
      Value<int> receivedAtMs,
      Value<int> createdAtMs,
      Value<int> rowid,
    });

class $$PaymentsTableFilterComposer
    extends Composer<_$AppDatabase, $PaymentsTable> {
  $$PaymentsTableFilterComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnFilters<String> get id => $composableBuilder(
    column: $table.id,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get saleId => $composableBuilder(
    column: $table.saleId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get customerId => $composableBuilder(
    column: $table.customerId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get locationId => $composableBuilder(
    column: $table.locationId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get method => $composableBuilder(
    column: $table.method,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get providerKey => $composableBuilder(
    column: $table.providerKey,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get amountCents => $composableBuilder(
    column: $table.amountCents,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get reference => $composableBuilder(
    column: $table.reference,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get receivedAtMs => $composableBuilder(
    column: $table.receivedAtMs,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get createdAtMs => $composableBuilder(
    column: $table.createdAtMs,
    builder: (column) => ColumnFilters(column),
  );
}

class $$PaymentsTableOrderingComposer
    extends Composer<_$AppDatabase, $PaymentsTable> {
  $$PaymentsTableOrderingComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnOrderings<String> get id => $composableBuilder(
    column: $table.id,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get saleId => $composableBuilder(
    column: $table.saleId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get customerId => $composableBuilder(
    column: $table.customerId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get locationId => $composableBuilder(
    column: $table.locationId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get method => $composableBuilder(
    column: $table.method,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get providerKey => $composableBuilder(
    column: $table.providerKey,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get amountCents => $composableBuilder(
    column: $table.amountCents,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get reference => $composableBuilder(
    column: $table.reference,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get receivedAtMs => $composableBuilder(
    column: $table.receivedAtMs,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get createdAtMs => $composableBuilder(
    column: $table.createdAtMs,
    builder: (column) => ColumnOrderings(column),
  );
}

class $$PaymentsTableAnnotationComposer
    extends Composer<_$AppDatabase, $PaymentsTable> {
  $$PaymentsTableAnnotationComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  GeneratedColumn<String> get id =>
      $composableBuilder(column: $table.id, builder: (column) => column);

  GeneratedColumn<String> get saleId =>
      $composableBuilder(column: $table.saleId, builder: (column) => column);

  GeneratedColumn<String> get customerId => $composableBuilder(
    column: $table.customerId,
    builder: (column) => column,
  );

  GeneratedColumn<String> get locationId => $composableBuilder(
    column: $table.locationId,
    builder: (column) => column,
  );

  GeneratedColumn<String> get method =>
      $composableBuilder(column: $table.method, builder: (column) => column);

  GeneratedColumn<String> get providerKey => $composableBuilder(
    column: $table.providerKey,
    builder: (column) => column,
  );

  GeneratedColumn<int> get amountCents => $composableBuilder(
    column: $table.amountCents,
    builder: (column) => column,
  );

  GeneratedColumn<String> get reference =>
      $composableBuilder(column: $table.reference, builder: (column) => column);

  GeneratedColumn<int> get receivedAtMs => $composableBuilder(
    column: $table.receivedAtMs,
    builder: (column) => column,
  );

  GeneratedColumn<int> get createdAtMs => $composableBuilder(
    column: $table.createdAtMs,
    builder: (column) => column,
  );
}

class $$PaymentsTableTableManager
    extends
        RootTableManager<
          _$AppDatabase,
          $PaymentsTable,
          Payment,
          $$PaymentsTableFilterComposer,
          $$PaymentsTableOrderingComposer,
          $$PaymentsTableAnnotationComposer,
          $$PaymentsTableCreateCompanionBuilder,
          $$PaymentsTableUpdateCompanionBuilder,
          (Payment, BaseReferences<_$AppDatabase, $PaymentsTable, Payment>),
          Payment,
          PrefetchHooks Function()
        > {
  $$PaymentsTableTableManager(_$AppDatabase db, $PaymentsTable table)
    : super(
        TableManagerState(
          db: db,
          table: table,
          createFilteringComposer: () =>
              $$PaymentsTableFilterComposer($db: db, $table: table),
          createOrderingComposer: () =>
              $$PaymentsTableOrderingComposer($db: db, $table: table),
          createComputedFieldComposer: () =>
              $$PaymentsTableAnnotationComposer($db: db, $table: table),
          updateCompanionCallback:
              ({
                Value<String> id = const Value.absent(),
                Value<String?> saleId = const Value.absent(),
                Value<String?> customerId = const Value.absent(),
                Value<String?> locationId = const Value.absent(),
                Value<String> method = const Value.absent(),
                Value<String?> providerKey = const Value.absent(),
                Value<int> amountCents = const Value.absent(),
                Value<String?> reference = const Value.absent(),
                Value<int> receivedAtMs = const Value.absent(),
                Value<int> createdAtMs = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => PaymentsCompanion(
                id: id,
                saleId: saleId,
                customerId: customerId,
                locationId: locationId,
                method: method,
                providerKey: providerKey,
                amountCents: amountCents,
                reference: reference,
                receivedAtMs: receivedAtMs,
                createdAtMs: createdAtMs,
                rowid: rowid,
              ),
          createCompanionCallback:
              ({
                required String id,
                Value<String?> saleId = const Value.absent(),
                Value<String?> customerId = const Value.absent(),
                Value<String?> locationId = const Value.absent(),
                required String method,
                Value<String?> providerKey = const Value.absent(),
                required int amountCents,
                Value<String?> reference = const Value.absent(),
                required int receivedAtMs,
                required int createdAtMs,
                Value<int> rowid = const Value.absent(),
              }) => PaymentsCompanion.insert(
                id: id,
                saleId: saleId,
                customerId: customerId,
                locationId: locationId,
                method: method,
                providerKey: providerKey,
                amountCents: amountCents,
                reference: reference,
                receivedAtMs: receivedAtMs,
                createdAtMs: createdAtMs,
                rowid: rowid,
              ),
          withReferenceMapper: (p0) => p0
              .map((e) => (e.readTable(table), BaseReferences(db, table, e)))
              .toList(),
          prefetchHooksCallback: null,
        ),
      );
}

typedef $$PaymentsTableProcessedTableManager =
    ProcessedTableManager<
      _$AppDatabase,
      $PaymentsTable,
      Payment,
      $$PaymentsTableFilterComposer,
      $$PaymentsTableOrderingComposer,
      $$PaymentsTableAnnotationComposer,
      $$PaymentsTableCreateCompanionBuilder,
      $$PaymentsTableUpdateCompanionBuilder,
      (Payment, BaseReferences<_$AppDatabase, $PaymentsTable, Payment>),
      Payment,
      PrefetchHooks Function()
    >;
typedef $$CustomersTableCreateCompanionBuilder =
    CustomersCompanion Function({
      required String id,
      required String name,
      Value<String?> phone,
      Value<String?> note,
      required int createdAtMs,
      required int updatedAtMs,
      Value<int?> deletedAtMs,
      Value<int> rowid,
    });
typedef $$CustomersTableUpdateCompanionBuilder =
    CustomersCompanion Function({
      Value<String> id,
      Value<String> name,
      Value<String?> phone,
      Value<String?> note,
      Value<int> createdAtMs,
      Value<int> updatedAtMs,
      Value<int?> deletedAtMs,
      Value<int> rowid,
    });

class $$CustomersTableFilterComposer
    extends Composer<_$AppDatabase, $CustomersTable> {
  $$CustomersTableFilterComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnFilters<String> get id => $composableBuilder(
    column: $table.id,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get name => $composableBuilder(
    column: $table.name,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get phone => $composableBuilder(
    column: $table.phone,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get note => $composableBuilder(
    column: $table.note,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get createdAtMs => $composableBuilder(
    column: $table.createdAtMs,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get updatedAtMs => $composableBuilder(
    column: $table.updatedAtMs,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get deletedAtMs => $composableBuilder(
    column: $table.deletedAtMs,
    builder: (column) => ColumnFilters(column),
  );
}

class $$CustomersTableOrderingComposer
    extends Composer<_$AppDatabase, $CustomersTable> {
  $$CustomersTableOrderingComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnOrderings<String> get id => $composableBuilder(
    column: $table.id,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get name => $composableBuilder(
    column: $table.name,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get phone => $composableBuilder(
    column: $table.phone,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get note => $composableBuilder(
    column: $table.note,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get createdAtMs => $composableBuilder(
    column: $table.createdAtMs,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get updatedAtMs => $composableBuilder(
    column: $table.updatedAtMs,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get deletedAtMs => $composableBuilder(
    column: $table.deletedAtMs,
    builder: (column) => ColumnOrderings(column),
  );
}

class $$CustomersTableAnnotationComposer
    extends Composer<_$AppDatabase, $CustomersTable> {
  $$CustomersTableAnnotationComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  GeneratedColumn<String> get id =>
      $composableBuilder(column: $table.id, builder: (column) => column);

  GeneratedColumn<String> get name =>
      $composableBuilder(column: $table.name, builder: (column) => column);

  GeneratedColumn<String> get phone =>
      $composableBuilder(column: $table.phone, builder: (column) => column);

  GeneratedColumn<String> get note =>
      $composableBuilder(column: $table.note, builder: (column) => column);

  GeneratedColumn<int> get createdAtMs => $composableBuilder(
    column: $table.createdAtMs,
    builder: (column) => column,
  );

  GeneratedColumn<int> get updatedAtMs => $composableBuilder(
    column: $table.updatedAtMs,
    builder: (column) => column,
  );

  GeneratedColumn<int> get deletedAtMs => $composableBuilder(
    column: $table.deletedAtMs,
    builder: (column) => column,
  );
}

class $$CustomersTableTableManager
    extends
        RootTableManager<
          _$AppDatabase,
          $CustomersTable,
          Customer,
          $$CustomersTableFilterComposer,
          $$CustomersTableOrderingComposer,
          $$CustomersTableAnnotationComposer,
          $$CustomersTableCreateCompanionBuilder,
          $$CustomersTableUpdateCompanionBuilder,
          (Customer, BaseReferences<_$AppDatabase, $CustomersTable, Customer>),
          Customer,
          PrefetchHooks Function()
        > {
  $$CustomersTableTableManager(_$AppDatabase db, $CustomersTable table)
    : super(
        TableManagerState(
          db: db,
          table: table,
          createFilteringComposer: () =>
              $$CustomersTableFilterComposer($db: db, $table: table),
          createOrderingComposer: () =>
              $$CustomersTableOrderingComposer($db: db, $table: table),
          createComputedFieldComposer: () =>
              $$CustomersTableAnnotationComposer($db: db, $table: table),
          updateCompanionCallback:
              ({
                Value<String> id = const Value.absent(),
                Value<String> name = const Value.absent(),
                Value<String?> phone = const Value.absent(),
                Value<String?> note = const Value.absent(),
                Value<int> createdAtMs = const Value.absent(),
                Value<int> updatedAtMs = const Value.absent(),
                Value<int?> deletedAtMs = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => CustomersCompanion(
                id: id,
                name: name,
                phone: phone,
                note: note,
                createdAtMs: createdAtMs,
                updatedAtMs: updatedAtMs,
                deletedAtMs: deletedAtMs,
                rowid: rowid,
              ),
          createCompanionCallback:
              ({
                required String id,
                required String name,
                Value<String?> phone = const Value.absent(),
                Value<String?> note = const Value.absent(),
                required int createdAtMs,
                required int updatedAtMs,
                Value<int?> deletedAtMs = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => CustomersCompanion.insert(
                id: id,
                name: name,
                phone: phone,
                note: note,
                createdAtMs: createdAtMs,
                updatedAtMs: updatedAtMs,
                deletedAtMs: deletedAtMs,
                rowid: rowid,
              ),
          withReferenceMapper: (p0) => p0
              .map((e) => (e.readTable(table), BaseReferences(db, table, e)))
              .toList(),
          prefetchHooksCallback: null,
        ),
      );
}

typedef $$CustomersTableProcessedTableManager =
    ProcessedTableManager<
      _$AppDatabase,
      $CustomersTable,
      Customer,
      $$CustomersTableFilterComposer,
      $$CustomersTableOrderingComposer,
      $$CustomersTableAnnotationComposer,
      $$CustomersTableCreateCompanionBuilder,
      $$CustomersTableUpdateCompanionBuilder,
      (Customer, BaseReferences<_$AppDatabase, $CustomersTable, Customer>),
      Customer,
      PrefetchHooks Function()
    >;
typedef $$PaymentProvidersTableCreateCompanionBuilder =
    PaymentProvidersCompanion Function({
      required String providerKey,
      required String kind,
      Value<String?> name,
      Value<bool> enabled,
      Value<bool> favorite,
      required int updatedAtMs,
      Value<int> rowid,
    });
typedef $$PaymentProvidersTableUpdateCompanionBuilder =
    PaymentProvidersCompanion Function({
      Value<String> providerKey,
      Value<String> kind,
      Value<String?> name,
      Value<bool> enabled,
      Value<bool> favorite,
      Value<int> updatedAtMs,
      Value<int> rowid,
    });

class $$PaymentProvidersTableFilterComposer
    extends Composer<_$AppDatabase, $PaymentProvidersTable> {
  $$PaymentProvidersTableFilterComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnFilters<String> get providerKey => $composableBuilder(
    column: $table.providerKey,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get kind => $composableBuilder(
    column: $table.kind,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get name => $composableBuilder(
    column: $table.name,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<bool> get enabled => $composableBuilder(
    column: $table.enabled,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<bool> get favorite => $composableBuilder(
    column: $table.favorite,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get updatedAtMs => $composableBuilder(
    column: $table.updatedAtMs,
    builder: (column) => ColumnFilters(column),
  );
}

class $$PaymentProvidersTableOrderingComposer
    extends Composer<_$AppDatabase, $PaymentProvidersTable> {
  $$PaymentProvidersTableOrderingComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnOrderings<String> get providerKey => $composableBuilder(
    column: $table.providerKey,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get kind => $composableBuilder(
    column: $table.kind,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get name => $composableBuilder(
    column: $table.name,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<bool> get enabled => $composableBuilder(
    column: $table.enabled,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<bool> get favorite => $composableBuilder(
    column: $table.favorite,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get updatedAtMs => $composableBuilder(
    column: $table.updatedAtMs,
    builder: (column) => ColumnOrderings(column),
  );
}

class $$PaymentProvidersTableAnnotationComposer
    extends Composer<_$AppDatabase, $PaymentProvidersTable> {
  $$PaymentProvidersTableAnnotationComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  GeneratedColumn<String> get providerKey => $composableBuilder(
    column: $table.providerKey,
    builder: (column) => column,
  );

  GeneratedColumn<String> get kind =>
      $composableBuilder(column: $table.kind, builder: (column) => column);

  GeneratedColumn<String> get name =>
      $composableBuilder(column: $table.name, builder: (column) => column);

  GeneratedColumn<bool> get enabled =>
      $composableBuilder(column: $table.enabled, builder: (column) => column);

  GeneratedColumn<bool> get favorite =>
      $composableBuilder(column: $table.favorite, builder: (column) => column);

  GeneratedColumn<int> get updatedAtMs => $composableBuilder(
    column: $table.updatedAtMs,
    builder: (column) => column,
  );
}

class $$PaymentProvidersTableTableManager
    extends
        RootTableManager<
          _$AppDatabase,
          $PaymentProvidersTable,
          PaymentProvider,
          $$PaymentProvidersTableFilterComposer,
          $$PaymentProvidersTableOrderingComposer,
          $$PaymentProvidersTableAnnotationComposer,
          $$PaymentProvidersTableCreateCompanionBuilder,
          $$PaymentProvidersTableUpdateCompanionBuilder,
          (
            PaymentProvider,
            BaseReferences<
              _$AppDatabase,
              $PaymentProvidersTable,
              PaymentProvider
            >,
          ),
          PaymentProvider,
          PrefetchHooks Function()
        > {
  $$PaymentProvidersTableTableManager(
    _$AppDatabase db,
    $PaymentProvidersTable table,
  ) : super(
        TableManagerState(
          db: db,
          table: table,
          createFilteringComposer: () =>
              $$PaymentProvidersTableFilterComposer($db: db, $table: table),
          createOrderingComposer: () =>
              $$PaymentProvidersTableOrderingComposer($db: db, $table: table),
          createComputedFieldComposer: () =>
              $$PaymentProvidersTableAnnotationComposer($db: db, $table: table),
          updateCompanionCallback:
              ({
                Value<String> providerKey = const Value.absent(),
                Value<String> kind = const Value.absent(),
                Value<String?> name = const Value.absent(),
                Value<bool> enabled = const Value.absent(),
                Value<bool> favorite = const Value.absent(),
                Value<int> updatedAtMs = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => PaymentProvidersCompanion(
                providerKey: providerKey,
                kind: kind,
                name: name,
                enabled: enabled,
                favorite: favorite,
                updatedAtMs: updatedAtMs,
                rowid: rowid,
              ),
          createCompanionCallback:
              ({
                required String providerKey,
                required String kind,
                Value<String?> name = const Value.absent(),
                Value<bool> enabled = const Value.absent(),
                Value<bool> favorite = const Value.absent(),
                required int updatedAtMs,
                Value<int> rowid = const Value.absent(),
              }) => PaymentProvidersCompanion.insert(
                providerKey: providerKey,
                kind: kind,
                name: name,
                enabled: enabled,
                favorite: favorite,
                updatedAtMs: updatedAtMs,
                rowid: rowid,
              ),
          withReferenceMapper: (p0) => p0
              .map((e) => (e.readTable(table), BaseReferences(db, table, e)))
              .toList(),
          prefetchHooksCallback: null,
        ),
      );
}

typedef $$PaymentProvidersTableProcessedTableManager =
    ProcessedTableManager<
      _$AppDatabase,
      $PaymentProvidersTable,
      PaymentProvider,
      $$PaymentProvidersTableFilterComposer,
      $$PaymentProvidersTableOrderingComposer,
      $$PaymentProvidersTableAnnotationComposer,
      $$PaymentProvidersTableCreateCompanionBuilder,
      $$PaymentProvidersTableUpdateCompanionBuilder,
      (
        PaymentProvider,
        BaseReferences<_$AppDatabase, $PaymentProvidersTable, PaymentProvider>,
      ),
      PaymentProvider,
      PrefetchHooks Function()
    >;

class $AppDatabaseManager {
  final _$AppDatabase _db;
  $AppDatabaseManager(this._db);
  $$ProductsTableTableManager get products =>
      $$ProductsTableTableManager(_db, _db.products);
  $$ProductMaterialsTableTableManager get productMaterials =>
      $$ProductMaterialsTableTableManager(_db, _db.productMaterials);
  $$MovementsTableTableManager get movements =>
      $$MovementsTableTableManager(_db, _db.movements);
  $$OutboxEntriesTableTableManager get outboxEntries =>
      $$OutboxEntriesTableTableManager(_db, _db.outboxEntries);
  $$SyncMetaEntriesTableTableManager get syncMetaEntries =>
      $$SyncMetaEntriesTableTableManager(_db, _db.syncMetaEntries);
  $$AppSettingsTableTableManager get appSettings =>
      $$AppSettingsTableTableManager(_db, _db.appSettings);
  $$LocationsTableTableManager get locations =>
      $$LocationsTableTableManager(_db, _db.locations);
  $$SalesTableTableManager get sales =>
      $$SalesTableTableManager(_db, _db.sales);
  $$PaymentsTableTableManager get payments =>
      $$PaymentsTableTableManager(_db, _db.payments);
  $$CustomersTableTableManager get customers =>
      $$CustomersTableTableManager(_db, _db.customers);
  $$PaymentProvidersTableTableManager get paymentProviders =>
      $$PaymentProvidersTableTableManager(_db, _db.paymentProviders);
}
