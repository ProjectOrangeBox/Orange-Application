<div rv-refresh="refresh.grid" rv-show="show.grid" class="masthead container" model="<?= getUrl('peopleReadAll') ?>" on-success-property="list">
    <table id="table" class="table table-striped">
        <thead>
            <tr>
                <th class="text-center">#</th>
                <th>First</th>
                <th>Last</th>
                <th class="text-center">Age</th>
                <th>Color</th>
                <th class="text-end">
                    <button type="button" rv-on-click="actions.create" hide="show.grid" show="show.create" class="btn btn-primary"><i class="fa-solid fa-square-plus"></i></button>
                </th>
            </tr>
        </thead>
        <tbody>
            <tr rv-each-row="list">
                <td class="text-center">{row.id}</td>
                <td>{row.firstname}</td>
                <td>{row.lastname}</td>
                <td class="text-center">{row.age}</td>
                <td>{row.colorname}</td>
                <td class="text-end">
                    <button type="button" rv-on-click="actions.go" rv-model="'<?= getUrl('peopleReadOne', ['{1}'], true) ?>' | replace row.id" on-success-property="readRecord" on-success-show="show.read" on-success-hide="show.grid" class="btn btn-primary"><i class="fa-solid fa-eye"></i></button>
                    <button type="button" rv-on-click="actions.update | args $index" show="show.update" class="btn btn-primary"><i class="fa-solid fa-square-pen"></i></button>
                    <button type="button" rv-on-click="actions.delete | args $index" show="show.delete" class="btn btn-danger"><i class="fa-solid fa-trash-can"></i></button>
                </td>
            </tr>
        </tbody>
    </table>
</div>