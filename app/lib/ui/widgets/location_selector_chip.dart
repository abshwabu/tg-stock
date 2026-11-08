import 'package:flutter/material.dart';

import '../../database/app_database.dart';
import '../../repositories/ledger_repository.dart';

/// A location indicator & switcher chip shown on Home and POS screens.
/// If `locations_enabled` is false, this widget renders nothing.
class LocationSelectorChip extends StatefulWidget {
  final LedgerRepository repository;
  final ValueChanged<Location>? onLocationChanged;

  const LocationSelectorChip({
    super.key,
    required this.repository,
    this.onLocationChanged,
  });

  @override
  State<LocationSelectorChip> createState() => _LocationSelectorChipState();
}

class _LocationSelectorChipState extends State<LocationSelectorChip> {
  bool _locationsEnabled = false;
  Location? _activeLocation;
  List<Location> _locations = [];

  @override
  void initState() {
    super.initState();
    _refresh();
  }

  Future<void> _refresh() async {
    final enabled = await widget.repository.getLocationsEnabled();
    if (!enabled) {
      if (mounted) setState(() => _locationsEnabled = false);
      return;
    }

    final active = await widget.repository.getActiveLocation();
    final all = await widget.repository.listLocations();

    if (mounted) {
      setState(() {
        _locationsEnabled = true;
        _activeLocation = active;
        _locations = all;
      });
    }
  }

  Future<void> _showLocationPicker() async {
    final selected = await showModalBottomSheet<Location>(
      context: context,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(16)),
      ),
      builder: (ctx) {
        return SafeArea(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Padding(
                padding: const EdgeInsets.all(16),
                child: Text(
                  'የመሳሪያው የስራ ቦታ / Active Location',
                  style: Theme.of(ctx).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.bold),
                ),
              ),
              const Divider(height: 1),
              ..._locations.map((loc) {
                final isSelected = loc.id == _activeLocation?.id;
                return ListTile(
                  leading: Icon(
                    loc.kind == 'warehouse' ? Icons.warehouse_rounded : Icons.storefront_rounded,
                    color: isSelected ? Theme.of(ctx).colorScheme.primary : Theme.of(ctx).colorScheme.onSurfaceVariant,
                  ),
                  title: Text(
                    loc.name,
                    style: TextStyle(
                      fontWeight: isSelected ? FontWeight.bold : FontWeight.normal,
                      color: isSelected ? Theme.of(ctx).colorScheme.primary : Theme.of(ctx).colorScheme.onSurface,
                    ),
                  ),
                  trailing: isSelected
                      ? Icon(Icons.check_circle, color: Theme.of(ctx).colorScheme.primary)
                      : null,
                  onTap: () => Navigator.of(ctx).pop(loc),
                );
              }),
              const SizedBox(height: 12),
            ],
          ),
        );
      },
    );

    if (selected != null && selected.id != _activeLocation?.id) {
      await widget.repository.setActiveLocationId(selected.id);
      if (mounted) {
        setState(() => _activeLocation = selected);
      }
      widget.onLocationChanged?.call(selected);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (!_locationsEnabled || _activeLocation == null) {
      return const SizedBox.shrink();
    }

    final primary = Theme.of(context).colorScheme.primary;
    return ActionChip(
      avatar: Icon(
        Icons.location_on,
        size: 16,
        color: primary,
      ),
      label: Text(
        '${_activeLocation!.name} ▾',
        style: TextStyle(
          fontWeight: FontWeight.bold,
          fontSize: 12.5,
          color: primary,
        ),
      ),
      backgroundColor: primary.withValues(alpha: 0.12),
      side: BorderSide(color: primary, width: 0.8),
      padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 0),
      onPressed: _showLocationPicker,
    );
  }
}
