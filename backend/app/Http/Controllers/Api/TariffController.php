<?php

namespace App\Http\Controllers\Api;

use App\Domain\Access\Access;
use App\Domain\Master\MasterImporter;
use App\Domain\Master\MasterService;
use App\Domain\Tariff\TariffLineImporter;
use App\Domain\Tariff\TariffResolver;
use App\Domain\Tariff\TariffService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Tariffs: selling and buying rate tables with versions and maker-checker approval, their lines, markup rules and the
 * resolver that prices a move. Viewing needs rate.view; buying prices, markups and margins also need rate.buy.view.
 */
class TariffController extends Controller
{
    private function service(Request $r): TariffService
    {
        return new TariffService(OrderController::storeFor($r));
    }

    private function viewer(Request $r): TariffService
    {
        Access::authorize($r, 'rate.view', 'Akun Anda tidak boleh melihat tarif.');

        return $this->service($r);
    }

    private function manager(Request $r): TariffService
    {
        Access::authorize($r, 'rate.manage', 'Akun Anda tidak boleh mengubah tarif.');

        return $this->service($r);
    }

    private function approver(Request $r): TariffService
    {
        Access::authorize($r, 'rate.approve', 'Akun Anda tidak boleh menyetujui tarif.');

        return $this->service($r);
    }

    private function buyer(Request $r): TariffService
    {
        Access::authorize($r, 'rate.buy.view', 'Akun Anda tidak boleh melihat tarif beli.');

        return $this->service($r);
    }

    public function schema()
    {
        return response()->json(['data' => ['bases' => TariffService::BASES]]);
    }

    public function index(Request $r)
    {
        return response()->json($this->viewer($r)->list($r->query('side'), $r->query('status'), $r->query('q'), max(1, (int) $r->query('page', 1)), Access::can($r, 'rate.buy.view')));
    }

    public function show(Request $r, string $id)
    {
        return response()->json(['data' => $this->viewer($r)->show($id, Access::can($r, 'rate.buy.view'))]);
    }

    public function store(Request $r)
    {
        return response()->json(['data' => $this->manager($r)->create($r->all())], 201);
    }

    public function update(Request $r, string $id)
    {
        return response()->json(['data' => $this->manager($r)->update($id, $r->all())]);
    }

    public function addLine(Request $r, string $id)
    {
        return response()->json(['data' => $this->manager($r)->addLine($id, $r->all())], 201);
    }

    public function updateLine(Request $r, string $id, string $line)
    {
        return response()->json(['data' => $this->manager($r)->updateLine($id, $line, $r->all())]);
    }

    public function deleteLine(Request $r, string $id, string $line)
    {
        $this->manager($r)->deleteLine($id, $line);

        return response()->noContent();
    }

    public function setLineMargin(Request $r, string $id, string $line)
    {
        return response()->json(['data' => $this->manager($r)->setLineMargin($id, $line, $r->all())]);
    }

    public function removeLineMargin(Request $r, string $id, string $line, string $margin)
    {
        $this->manager($r)->removeLineMargin($id, $line, $margin);

        return response()->noContent();
    }

    public function approve(Request $r, string $id)
    {
        return response()->json(['data' => $this->approver($r)->approve($id)]);
    }

    public function retire(Request $r, string $id)
    {
        $d = $r->validate(['reason' => 'required|string|min:5|max:500']);

        return response()->json(['data' => $this->approver($r)->retire($id, $d['reason'])]);
    }

    /** Sends a vendor's submitted rate back to the vendor with the reason. */
    public function rejectProposal(Request $r, string $id)
    {
        $d = $r->validate(['note' => 'required|string|min:5|max:500']);

        return response()->json(['data' => $this->approver($r)->rejectProposal($id, $d['note'])]);
    }

    public function newVersion(Request $r, string $id)
    {
        return response()->json(['data' => $this->manager($r)->newVersion($id, (string) $r->input('valid_from'))], 201);
    }

    public function markups(Request $r)
    {
        return response()->json(['data' => $this->buyer($r)->markups()]);
    }

    public function storeMarkup(Request $r)
    {
        return response()->json(['data' => $this->manager($r)->createMarkup($r->all())], 201);
    }

    public function updateMarkup(Request $r, string $id)
    {
        return response()->json(['data' => $this->manager($r)->updateMarkup($id, $r->all())]);
    }

    public function markupStatus(Request $r, string $id)
    {
        $d = $r->validate(['active' => 'required|boolean']);

        return response()->json(['data' => $this->manager($r)->setMarkupActive($id, (bool) $d['active'])]);
    }

    private function lineImporter(Request $r): TariffLineImporter
    {
        Access::authorize($r, 'rate.manage', 'Akun Anda tidak boleh mengubah tarif.');
        $store = OrderController::storeFor($r);
        $tariff = new TariffService($store);

        return new TariffLineImporter($store, $tariff, new MasterImporter($store, new MasterService($store), $tariff));
    }

    public function lineTemplate(Request $r)
    {
        $file = $this->lineImporter($r)->template($r->query('format') === 'csv' ? 'csv' : 'xlsx');

        return response()->download($file[0], $file[1])->deleteFileAfterSend(true);
    }

    public function importLines(Request $r, string $id)
    {
        $r->validate(['file' => 'required|file']);

        return response()->json(['data' => $this->lineImporter($r)->preview($id, $r->file('file'))], 201);
    }

    public function resolve(Request $r)
    {
        $this->viewer($r);
        $result = (new TariffResolver(OrderController::storeFor($r)))->resolve($r->all());

        return response()->json(['data' => Access::can($r, 'rate.buy.view') ? $result : TariffResolver::withoutBuy($result)]);
    }
}
