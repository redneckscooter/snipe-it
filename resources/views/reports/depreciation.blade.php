@extends('layouts/default')

{{-- Page title --}}
@section('title')
{{ trans('general.depreciation_report') }}
@parent
@stop

{{-- Page content --}}
@section('content')

<div class="row">
    <div class="col-md-12">
        <div class="box box-default">

            <div class="box-header with-border">
                <h3 class="box-title">
                    {{ trans('general.depreciation_report') }}
                </h3>
            </div>

            <div class="box-body">

                {{-- Depreciation Date Selection --}}
                <form method="GET"
                    action="{{ route('reports/depreciation') }}"
                    style="margin-bottom: 20px;">

                    <div class="form-inline">

                        <div class="form-group">
                            <label for="depreciation_date">
                                Depreciation As Of
                            </label>

                            <input type="date"
                                name="depreciation_date"
                                id="depreciation_date"
                                class="form-control"
                                value="{{ request('depreciation_date', date('Y-m-d')) }}"
                                required
                                style="margin-left: 8px;">
                        </div>

                        <button type="submit"
                                class="btn btn-primary"
                                style="margin-left: 8px;">
                            <i class="fa fa-search"></i>
                            View Report
                        </button>

                        <a href="{{ route('reports/depreciation') }}"
                        class="btn btn-default"
                        style="margin-left: 5px;">
                            <i class="fa fa-refresh"></i>
                            Today
                        </a>

                    </div>

                </form>

                @if (($depreciations) && ($depreciations->count() > 0))

                    <div class="row" style="margin-bottom: 10px;">
                        <div class="col-md-12">
                            <strong>
                                Depreciation calculated as of:
                                {{ \Carbon\Carbon::parse(request('depreciation_date', date('Y-m-d')))->format('m/d/Y') }}
                            </strong>
                        </div>
                    </div>

                    <table
                        data-cookie-id-table="depreciationReport"
                        data-id-table="depreciationReport"
                        data-side-pagination="server"
                        data-sort-order="desc"
                        data-sort-name="created_at"
                        data-show-footer="true"
                        id="depreciationReport"
                        data-advanced-search="false"
                        data-url="{{ route('api.depreciation-report.index') }}?depreciation_date={{ urlencode(request('depreciation_date', date('Y-m-d'))) }}"
                        data-mobile-responsive="true"
                        {{-- data-toggle="table" --}}
                        class="table table-striped snipe-table"
                        data-columns="{{ \App\Presenters\DepreciationReportPresenter::dataTableLayout() }}"
                        data-export-options='{
                          "fileName": "depreciation-report-{{ request('depreciation_date', date('Y-m-d')) }}",
                          "ignoreColumn": ["actions","image","change","checkbox","checkincheckout","icon"]
                          }'>
                    </table>

                @else

                    <div class="col-md-12">
                        <x-alert type="warning" icon="warning">
                            {!! trans('admin/depreciations/general.no_depreciations_warning') !!}
                        </x-alert>
                    </div>

                @endif

            </div> <!-- /.box-body-->

        </div> <!--/box.box-default-->

    </div> <!-- /.col-md-12-->
</div> <!--/.row-->

@stop

@section('moar_scripts')
    @include ('partials.bootstrap-table')
@stop
