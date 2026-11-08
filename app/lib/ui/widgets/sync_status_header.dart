import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../services/sync_engine.dart';

class SyncStatusHeader extends StatelessWidget {
  final SyncEngine syncEngine;

  const SyncStatusHeader({super.key, required this.syncEngine});

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder<SyncEngineState>(
      valueListenable: syncEngine.stateNotifier,
      builder: (context, state, _) {
        final colorScheme = Theme.of(context).colorScheme;

        Color bg;
        Color fg;
        IconData icon;
        String statusTitle;

        switch (state.status) {
          case SyncStatus.offline:
            bg = Colors.grey.shade200;
            fg = Colors.grey.shade800;
            icon = Icons.cloud_off_rounded;
            statusTitle = 'Offline / ኦፍላይን';
            break;
          case SyncStatus.syncing:
            bg = Colors.blue.shade50;
            fg = Colors.blue.shade900;
            icon = Icons.sync_rounded;
            statusTitle = 'Syncing... / እየተመሳሰለ ነው...';
            break;
          case SyncStatus.idle:
            bg = Colors.green.shade50;
            fg = Colors.green.shade900;
            icon = Icons.cloud_done_rounded;
            statusTitle = 'Up to date / የተመሳሰለ';
            break;
          case SyncStatus.error:
            bg = Colors.orange.shade50;
            fg = Colors.orange.shade900;
            icon = Icons.sync_problem_rounded;
            statusTitle = 'Sync paused (retrying) / ጊዜያዊ ስህተት';
            break;
          case SyncStatus.unauthorized:
            bg = Colors.red.shade50;
            fg = Colors.red.shade900;
            icon = Icons.lock_clock_rounded;
            statusTitle = 'Re-pairing needed / እንደገና ያገናኙ';
            break;
        }

        String subtitle = '';
        if (state.lastSyncedAt != null) {
          final timeStr = DateFormat('HH:mm').format(state.lastSyncedAt!.toLocal());
          subtitle = 'Last synced $timeStr';
        }
        if (state.pendingCount > 0) {
          final pendingText = '${state.pendingCount} changes waiting';
          subtitle = subtitle.isNotEmpty ? '$subtitle • $pendingText' : pendingText;
        } else if (state.status == SyncStatus.idle && subtitle.isEmpty) {
          subtitle = 'All changes saved locally and online';
        }

        return Container(
          width: double.infinity,
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
          decoration: BoxDecoration(
            color: bg,
            border: Border(bottom: BorderSide(color: Colors.grey.shade300, width: 0.8)),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  if (state.status == SyncStatus.syncing)
                    const SizedBox(
                      width: 18,
                      height: 18,
                      child: CircularProgressIndicator(strokeWidth: 2.2),
                    )
                  else
                    Icon(icon, size: 20, color: fg),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      statusTitle,
                      style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13.5, color: fg),
                    ),
                  ),
                  if (state.status != SyncStatus.offline && state.status != SyncStatus.syncing)
                    InkWell(
                      onTap: () => syncEngine.syncNow(),
                      borderRadius: BorderRadius.circular(4),
                      child: Padding(
                        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                        child: Row(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            Icon(Icons.refresh, size: 16, color: colorScheme.primary),
                            const SizedBox(width: 4),
                            Text(
                              'Sync now',
                              style: TextStyle(
                                fontSize: 12,
                                fontWeight: FontWeight.w600,
                                color: colorScheme.primary,
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),
                ],
              ),
              if (subtitle.isNotEmpty) ...[
                const SizedBox(height: 2),
                Padding(
                  padding: const EdgeInsets.only(left: 28),
                  child: Text(
                    subtitle,
                    style: TextStyle(fontSize: 11.5, color: fg.withValues(alpha: 0.85)),
                  ),
                ),
              ],
              if (state.status == SyncStatus.error &&
                  state.errorMessage != null &&
                  state.errorMessage!.isNotEmpty) ...[
                const SizedBox(height: 4),
                Padding(
                  padding: const EdgeInsets.only(left: 28),
                  child: Text(
                    state.errorMessage!,
                    style: TextStyle(
                      fontSize: 11,
                      fontWeight: FontWeight.w500,
                      color: fg,
                    ),
                    maxLines: 3,
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
              ],
              if (state.hasNegativeStock) ...[
                const SizedBox(height: 6),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                  decoration: BoxDecoration(
                    color: Colors.amber.shade100,
                    borderRadius: BorderRadius.circular(6),
                    border: Border.all(color: Colors.amber.shade700, width: 0.8),
                  ),
                  child: Row(
                    children: [
                      Icon(Icons.warning_amber_rounded, size: 16, color: Colors.amber.shade900),
                      const SizedBox(width: 6),
                      Expanded(
                        child: Text(
                          '⚠️ Check stock / የስቶክ መጠን ያረጋግጡ (Negative count detected)',
                          style: TextStyle(
                            fontSize: 12,
                            fontWeight: FontWeight.bold,
                            color: Colors.amber.shade900,
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
              ],
              if (state.progressText != null && state.status == SyncStatus.syncing) ...[
                const SizedBox(height: 4),
                Padding(
                  padding: const EdgeInsets.only(left: 28),
                  child: Text(
                    state.progressText!,
                    style: TextStyle(fontSize: 11, fontStyle: FontStyle.italic, color: fg),
                  ),
                ),
              ],
            ],
          ),
        );
      },
    );
  }
}
