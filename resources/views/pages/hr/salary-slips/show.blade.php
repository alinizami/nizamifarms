@extends('layouts.app')

@section('title', 'Salary Slip Details')

@section('content')
<div class="container mx-auto px-4 py-6">
    <!-- Header -->
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">Salary Slip Details</h1>
            <p class="text-gray-600 mt-1">{{ $slip->slip_number ?? 'SLIP-' . $slip->id }}</p>
        </div>
        <div class="flex items-center gap-2">
            @if($slip->slip_status !== 'cancelled')
                <button onclick="confirmDeleteSlip()" class="kt-btn kt-btn-danger" style="background-color: #dc2626 !important; color: white !important;">
                    <i class="ki-filled ki-trash"></i> Delete & Rollback
                </button>
            @endif
            <a href="{{ route('hr.salary-slips.index') }}" class="kt-btn kt-btn-light">
                <i class="ki-filled ki-arrow-left"></i> Back to List
            </a>
        </div>
    </div>

    <!-- Salary Slip Card -->
    <div class="kt-card">
        <div class="kt-card-body">
            <!-- Employee & Month Info -->
            <div class="p-4 bg-blue-50 rounded-lg mb-6">
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div>
                        <label class="text-sm text-gray-600">Employee</label>
                        <div class="font-semibold">{{ $slip->employee->fullname ?? 'Unknown' }}</div>
                        <div class="text-xs text-gray-500">{{ $slip->employee->email ?? '' }}</div>
                    </div>
                    <div>
                        <label class="text-sm text-gray-600">Month</label>
                        <div class="font-semibold">{{ \Carbon\Carbon::parse($slip->salary_month)->format('F Y') }}</div>
                    </div>
                    <div>
                        <label class="text-sm text-gray-600">Status</label>
                        <div>
                            @if($slip->slip_status === 'approved')
                                <span class="px-2 py-1 bg-green-100 text-green-700 text-xs font-medium rounded">Approved</span>
                            @elseif($slip->slip_status === 'paid')
                                <span class="px-2 py-1 bg-blue-100 text-blue-700 text-xs font-medium rounded">Paid</span>
                            @else
                                <span class="px-2 py-1 bg-gray-100 text-gray-700 text-xs font-medium rounded">Draft</span>
                            @endif
                        </div>
                    </div>
                    <div>
                        <label class="text-sm text-gray-600">Net Salary</label>
                        <div class="font-bold text-xl text-purple-600">PKR {{ number_format($slip->net_salary, 2) }}</div>
                    </div>
                </div>
            </div>

            <!-- Earnings & Deductions -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                <!-- Earnings -->
                <div>
                    <h3 class="text-lg font-semibold text-gray-900 mb-4 flex items-center">
                        <span class="text-2xl mr-2">💰</span> Earnings
                    </h3>
                    <div class="space-y-3">
                        <div class="flex justify-between items-center">
                            <span class="text-gray-700">Base Salary</span>
                            <span class="font-semibold">PKR {{ number_format($slip->base_salary, 2) }}</span>
                        </div>
                        @if($slip->overtime_amount > 0)
                        <div class="flex justify-between items-center">
                            <span class="text-gray-700">
                                Overtime ({{ $slip->overtime_hours }} hrs)
                                @if($slip->overtime_overridden)
                                    <span class="text-xs text-orange-600">⚠ Overridden</span>
                                @endif
                            </span>
                            <span class="font-semibold text-green-600">PKR {{ number_format($slip->overtime_amount, 2) }}</span>
                        </div>
                        @endif
                        @if($slip->bonuses > 0)
                        <div class="flex justify-between items-center">
                            <span class="text-gray-700">Bonuses</span>
                            <span class="font-semibold text-green-600">PKR {{ number_format($slip->bonuses, 2) }}</span>
                        </div>
                        @endif
                        @if($slip->allowances > 0)
                        <div class="flex justify-between items-center">
                            <span class="text-gray-700">Allowances</span>
                            <span class="font-semibold text-green-600">PKR {{ number_format($slip->allowances, 2) }}</span>
                        </div>
                        @endif
                        @if($slip->other_earnings > 0)
                        <div class="flex justify-between items-center">
                            <span class="text-gray-700">Other Earnings</span>
                            <span class="font-semibold text-green-600">PKR {{ number_format($slip->other_earnings, 2) }}</span>
                        </div>
                        @if($slip->other_earnings_description)
                        <div class="text-xs text-gray-500 pl-4">{{ $slip->other_earnings_description }}</div>
                        @endif
                        @endif
                        <div class="flex justify-between items-center pt-3 border-t font-bold">
                            <span class="text-gray-900">Gross Salary</span>
                            <span class="text-green-600">PKR {{ number_format($slip->gross_salary, 2) }}</span>
                        </div>
                    </div>
                </div>

                <!-- Deductions -->
                <div>
                    <h3 class="text-lg font-semibold text-gray-900 mb-4 flex items-center">
                        <span class="text-2xl mr-2">➖</span> Deductions
                    </h3>
                    <div class="space-y-3">
                        {{-- Oct-2026 — shown whenever the month HAD lateness, not only when money
                             was taken: a fully waived month, or one inside the free buffer, must
                             still show its lateness and the waive, as Payroll and the phone do. --}}
                        @if($slip->hasLateness())
                        @php($lateNote = \App\Models\HR\SalarySlipModel::lateNote($slip->late_minutes, $slip->late_raw_minutes, $slip->late_waived_minutes))
                        <div class="flex justify-between items-center">
                            <span class="text-gray-700">
                                Late ({{ (int) round((float) $slip->late_minutes) }} mins counted)
                                @if($slip->late_deduction_overridden)
                                    <span class="text-xs text-orange-600">⚠ Overridden</span>
                                @endif
                                {{-- The minutes a manager forgave (and a figure typed by hand),
                                     worded once in SalarySlipModel::lateNote. Null on older slips. --}}
                                @if($lateNote)
                                    <span class="block text-xs text-gray-500">{{ $lateNote }}</span>
                                @endif
                            </span>
                            <span class="font-semibold {{ $slip->late_deduction > 0 ? 'text-red-600' : 'text-gray-500' }}">{{ $slip->late_deduction > 0 ? '-PKR ' . number_format($slip->late_deduction, 2) : 'no deduction' }}</span>
                        </div>
                        @endif
                        @if($slip->absent_deduction > 0)
                        <div class="flex justify-between items-center">
                            <span class="text-gray-700">
                                Absent ({{ $slip->absent_days }} days)
                                @if($slip->absent_deduction_overridden)
                                    <span class="text-xs text-orange-600">⚠ Overridden</span>
                                @endif
                            </span>
                            <span class="font-semibold text-red-600">-PKR {{ number_format($slip->absent_deduction, 2) }}</span>
                        </div>
                        @endif
                        @if($slip->salary_advance > 0)
                        <div class="flex justify-between items-center">
                            <span class="text-gray-700">
                                Salary Advance
                                @if($slip->salary_advance_overridden)
                                    <span class="text-xs text-orange-600">⚠ Overridden</span>
                                @endif
                            </span>
                            <span class="font-semibold text-red-600">-PKR {{ number_format($slip->salary_advance, 2) }}</span>
                        </div>
                        @endif
                        @if($slip->loan_installment > 0)
                        <div class="flex justify-between items-center">
                            <span class="text-gray-700">
                                Loan Installment
                                @if($slip->loan_installment_skipped)
                                    <span class="text-xs text-orange-600">⚠ Modified</span>
                                @endif
                            </span>
                            <span class="font-semibold text-red-600">-PKR {{ number_format($slip->loan_installment, 2) }}</span>
                        </div>
                        @endif
                        @if($slip->tax_deduction > 0)
                        <div class="flex justify-between items-center">
                            <span class="text-gray-700">Tax</span>
                            <span class="font-semibold text-red-600">-PKR {{ number_format($slip->tax_deduction, 2) }}</span>
                        </div>
                        @endif
                        @if($slip->other_deductions > 0)
                        <div class="flex justify-between items-center">
                            <span class="text-gray-700">Other Deductions</span>
                            <span class="font-semibold text-red-600">-PKR {{ number_format($slip->other_deductions, 2) }}</span>
                        </div>
                        @if($slip->other_deductions_description)
                        <div class="text-xs text-gray-500 pl-4">{{ $slip->other_deductions_description }}</div>
                        @endif
                        @endif
                        <div class="flex justify-between items-center pt-3 border-t font-bold">
                            <span class="text-gray-900">Total Deductions</span>
                            <span class="text-red-600">-PKR {{ number_format($slip->total_deductions, 2) }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Net Salary -->
            <div class="p-4 bg-gradient-to-r from-purple-50 to-blue-50 rounded-lg mb-6">
                <div class="flex justify-between items-center">
                    <span class="text-lg font-semibold text-gray-900">Net Salary (Amount to Pay)</span>
                    <span class="text-3xl font-bold text-purple-600">PKR {{ number_format($slip->net_salary, 2) }}</span>
                </div>
            </div>

            <!-- Attendance Summary -->
            @if($slip->working_days > 0)
            <div class="mb-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4 flex items-center">
                    <span class="text-2xl mr-2">📊</span> Attendance Summary
                </h3>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div class="p-3 bg-gray-50 rounded">
                        <div class="text-sm text-gray-600">Working Days</div>
                        <div class="font-bold text-lg">{{ $slip->working_days }}</div>
                    </div>
                    <div class="p-3 bg-green-50 rounded">
                        <div class="text-sm text-gray-600">Present Days</div>
                        <div class="font-bold text-lg text-green-600">{{ $slip->present_days }}</div>
                    </div>
                    <div class="p-3 bg-red-50 rounded">
                        <div class="text-sm text-gray-600">Absent Days</div>
                        <div class="font-bold text-lg text-red-600">{{ $slip->absent_days ?? 0 }}</div>
                    </div>
                    <div class="p-3 bg-blue-50 rounded">
                        <div class="text-sm text-gray-600">On Leave</div>
                        <div class="font-bold text-lg text-blue-600">{{ $slip->leave_days }}</div>
                    </div>
                </div>
            </div>
            @endif

            <!-- Manual Adjustments -->
            @if($slip->has_manual_adjustments && $slip->override_notes)
            <div class="p-4 bg-yellow-50 border border-yellow-200 rounded-lg mb-6">
                <div class="flex items-start">
                    <span class="text-2xl mr-3">⚠️</span>
                    <div>
                        <h4 class="font-semibold text-yellow-800 mb-2">Manual Adjustments/Overrides</h4>
                        <p class="text-sm text-yellow-700">{{ $slip->override_notes }}</p>
                    </div>
                </div>
            </div>
            @endif

            <!-- Metadata -->
            <div class="border-t pt-4">
                <h3 class="text-sm font-semibold text-gray-700 mb-3">Details</h3>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                    <div>
                        <span class="text-gray-600">Created By:</span>
                        <div class="font-medium">{{ $slip->creator->fullname ?? 'System' }}</div>
                    </div>
                    <div>
                        <span class="text-gray-600">Created At:</span>
                        <div class="font-medium">{{ $slip->created_at->format('Y-m-d H:i') }}</div>
                    </div>
                    @if($slip->approved_by)
                    <div>
                        <span class="text-gray-600">Approved By:</span>
                        <div class="font-medium">{{ $slip->approver->fullname ?? 'Unknown' }}</div>
                    </div>
                    <div>
                        <span class="text-gray-600">Approved At:</span>
                        <div class="font-medium">{{ $slip->approved_at ? $slip->approved_at->format('Y-m-d H:i') : 'N/A' }}</div>
                    </div>
                    @endif
                    @if($slip->ledger_transaction_id)
                    <div>
                        <span class="text-gray-600">Ledger Entry:</span>
                        <div class="font-medium">
                            <a href="{{ route('fin.ledger.show', $slip->ledger_transaction_id) }}" class="text-blue-600 hover:underline">
                                #{{ $slip->ledger_transaction_id }}
                            </a>
                        </div>
                    </div>
                    @endif
                </div>
            </div>

            <!-- Actions -->
            @if($slip->slip_status === 'draft' && auth()->user()->hasPermission('approve_salary_slips'))
            <div class="border-t pt-4 mt-4">
                <button onclick="approveSalarySlip()" class="kt-btn kt-btn-success">
                    <i class="ki-filled ki-check-circle"></i> Approve & Post to Ledger
                </button>
            </div>
            @endif
        </div>
    </div>
</div>

@if($slip->slip_status === 'draft')
<script>
function approveSalarySlip() {
    if (!confirm('Are you sure you want to approve this salary slip?\n\nThis will:\n- Create ledger entry\n- Update loan balances\n- Settle salary advances\n- Deduct from EXP_FUND')) {
        return;
    }

    fetch('{{ route("hr.salary-slips.approve", $slip->id) }}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Salary slip approved successfully!');
            location.reload();
        } else {
            alert('Error: ' + (data.message || 'Failed to approve'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error approving salary slip');
    });
}
</script>
@endif

<script>
// Delete function available for all users
function confirmDeleteSlip() {
    if (!confirm(`⚠️ WARNING: Delete Salary Slip?\n\nEmployee: {{ $slip->employee->fullname ?? 'Unknown' }}\nMonth: {{ \Carbon\Carbon::parse($slip->salary_month)->format('F Y') }}\n\nThis will:\n✓ Delete the salary slip\n✓ Reverse ledger entries\n✓ Restore account balances\n✓ Rollback loan installments\n✓ Unsettle salary advances\n\nThis action cannot be undone. Continue?`)) {
        return;
    }
    
    fetch('{{ url("/hr/salary-slips/" . $slip->id) }}', {
        method: 'DELETE',
        headers: {
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('✅ ' + data.message);
            window.location.href = '{{ route("hr.salary-slips.index") }}';
        } else {
            alert('❌ Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('❌ Error deleting salary slip');
    });
}
</script>

@endsection

